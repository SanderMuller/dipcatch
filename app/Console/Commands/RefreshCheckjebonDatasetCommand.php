<?php declare(strict_types=1);

namespace App\Console\Commands;

use DateTimeInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

#[Signature('dipcatch:refresh-checkjebon')]
#[Description('Refresh the local checkjebon.nl price dataset (every chain with rows) from the upstream daily JSON.')]
final class RefreshCheckjebonDatasetCommand extends Command
{
    private const string DATASET_URL = 'https://raw.githubusercontent.com/supermarkt/checkjebon/main/data/supermarkets.json';

    /**
     * The upstream file is ~10.1 MB today; the guard only trips on runaway
     * growth or a corrupted response.
     */
    private const int MAX_BODY_BYTES = 50 * 1024 * 1024;

    private const int UPSERT_CHUNK = 1000;

    public function handle(): int
    {
        $runStartedAt = now();

        try {
            $response = Http::timeout(120)->get(self::DATASET_URL);
        } catch (ConnectionException $e) {
            $this->error("Checkjebon fetch failed: {$e->getMessage()} — existing rows kept.");

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error("Checkjebon fetch failed: HTTP {$response->status()} — existing rows kept.");

            return self::FAILURE;
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            $this->error('Checkjebon dataset exceeds the 50 MB guard — existing rows kept.');

            return self::FAILURE;
        }

        try {
            $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error("Checkjebon dataset is not valid JSON: {$e->getMessage()} — existing rows kept.");

            return self::FAILURE;
        }

        if (! is_array($decoded)) {
            $this->error('Checkjebon dataset has an unexpected shape — existing rows kept.');

            return self::FAILURE;
        }

        // Every chain the payload carries, rather than a hard-coded list:
        // upstream adds and fills chains on its own schedule (ALDI and
        // Ekoplaza ship empty today), and a suggestion only needs the
        // chain's own base URL to build a product URL. Pricing is unaffected
        // — CheckjebonSource maps its own two hosts.
        foreach ($this->chainKeys($decoded) as $supermarket) {
            $rows = $this->rowsFor($decoded, $supermarket, $runStartedAt);

            if ($rows === []) {
                // An upstream scrape hiccup (like ALDI's standing 0 rows) must
                // not wipe local data — keep whatever the last good run stored.
                // No chain row either: one without prices under it is the
                // state this command exists to keep out of the table.
                Log::warning('Checkjebon refresh: supermarket empty or missing upstream; rows kept.', [
                    'supermarket' => $supermarket,
                ]);
                $this->warn("No '{$supermarket}' rows upstream — existing rows kept.");

                continue;
            }

            foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
                DB::table('checkjebon_prices')->upsert(
                    $chunk,
                    ['supermarket', 'external_id'],
                    ['name', 'price', 'size', 'link', 'refreshed_at'],
                );
            }

            $pruned = DB::table('checkjebon_prices')
                ->where('supermarket', $supermarket)
                ->where('refreshed_at', '<', $runStartedAt)
                ->delete();

            // Last, so a chain row always implies that chain has prices. The
            // run is not transactional, so writing it first left a first-ever
            // import that died mid-upsert with a chain and nothing under it.
            // Prices with no chain row are the safe half of the pair:
            // `SuggestShops::freshChains()` reads the chain table, so
            // `candidateRows()` never selects them. That is a one-run gap
            // after a crash, but a permanent one for a chain whose upstream
            // entry carries no `u` — see `storeChain()`.
            $this->storeChain($decoded, $supermarket, $runStartedAt);

            $this->info(sprintf('%s: %d rows upserted, %d delisted rows pruned.', $supermarket, count($rows), $pruned));
        }

        $this->pruneChainsWithoutPrices();

        return self::SUCCESS;
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     * @return list<array{supermarket: string, external_id: string, name: string, price: string, size: ?string, link: string, refreshed_at: DateTimeInterface}>
     */
    private function rowsFor(array $decoded, string $supermarket, DateTimeInterface $refreshedAt): array
    {
        $products = $this->entryFor($decoded, $supermarket)['d'] ?? null;
        if (! is_array($products)) {
            return [];
        }

        $rows = [];

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $link = $product['l'] ?? null;
            $name = $product['n'] ?? null;
            $price = $product['p'] ?? null;

            if (! is_string($link) || $link === '' || ! is_string($name) || ! is_numeric($price)) {
                continue;
            }

            $externalId = $this->externalIdFromLink($supermarket, $link);
            if ($externalId === null) {
                continue;
            }

            $size = $product['s'] ?? null;

            $rows[$externalId] = [
                'supermarket' => $supermarket,
                'external_id' => $externalId,
                'name' => mb_substr($name, 0, 255),
                'price' => number_format((float) $price, 2, '.', ''),
                'size' => is_string($size) && $size !== '' ? mb_substr($size, 0, 255) : null,
                'link' => $link,
                'refreshed_at' => $refreshedAt,
            ];
        }

        return array_values($rows);
    }

    /**
     * AH links look like `wi257/ah-kruiden-roomkaas` — the `wi` id is the
     * match key CheckjebonSource looks up from an ah.nl URL. Lidl links are
     * the bare numeric boodschaapje product id, looked up the same way.
     * Every other chain is match-only: the link itself is the id, whether
     * that is a slug (`beemster-…-729228ZK`) or a number (`115217`).
     */
    private function externalIdFromLink(string $supermarket, string $link): ?string
    {
        if ($supermarket === 'ah') {
            return preg_match('/^(wi\d+)\//i', $link, $m) === 1 ? strtolower($m[1]) : null;
        }

        if ($supermarket === 'lidl') {
            return ctype_digit($link) ? $link : null;
        }

        return mb_substr($link, 0, 255);
    }

    /**
     * Repairs the orphan the old write order could leave behind: a chain row
     * whose first-ever price upsert died under it. Writing the chain last
     * stops new ones, but a database upgraded from the old order can already
     * hold one, and nothing reports it — a chain that stays empty upstream is
     * skipped by every later run, so the row would sit there for good.
     *
     * Safe to run unconditionally. A chain with prices never matches, and a
     * chain the payload no longer carries has kept its prices since the run
     * that stored them.
     */
    private function pruneChainsWithoutPrices(): void
    {
        $orphans = DB::table('checkjebon_chains')
            ->whereNotIn('chain', DB::table('checkjebon_prices')->distinct()->select('supermarket'))
            ->delete();

        if ($orphans > 0) {
            Log::warning('Checkjebon refresh: removed chain rows that held no prices.', ['count' => $orphans]);
            $this->warn("Removed {$orphans} chain row(s) with no prices.");
        }
    }

    /**
     * Store the chain's base URL + display name from the dataset's own `u`
     * and `c` fields, so a suggestion can build a product URL without
     * hard-coding one URL shape per chain.
     *
     * @param  array<array-key, mixed>  $decoded
     */
    private function storeChain(array $decoded, string $supermarket, DateTimeInterface $refreshedAt): void
    {
        $entry = $this->entryFor($decoded, $supermarket);

        $baseUrl = $entry['u'] ?? null;
        $label = $entry['c'] ?? null;

        if (! is_string($baseUrl) || $baseUrl === '') {
            // Permanent, not a gap that closes: prices keep arriving and no
            // chain row is ever written, so `freshChains()` never yields the
            // chain and nothing suggests it. Silence here reads as success.
            Log::warning('Checkjebon refresh: chain has no base URL upstream; it cannot be suggested.', [
                'supermarket' => $supermarket,
            ]);

            return;
        }

        DB::table('checkjebon_chains')->upsert(
            [[
                'chain' => $supermarket,
                'label' => is_string($label) && $label !== '' ? mb_substr($label, 0, 255) : $supermarket,
                'base_url' => $baseUrl,
                'refreshed_at' => $refreshedAt,
            ]],
            ['chain'],
            ['label', 'base_url', 'refreshed_at'],
        );
    }

    /**
     * The chain keys the payload declares, in payload order.
     *
     * @param  array<array-key, mixed>  $decoded
     * @return list<string>
     */
    private function chainKeys(array $decoded): array
    {
        $keys = [];

        foreach ($decoded as $entry) {
            $key = is_array($entry) ? ($entry['n'] ?? null) : null;

            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     * @return array<string, mixed>|null
     */
    private function entryFor(array $decoded, string $supermarket): ?array
    {
        foreach ($decoded as $candidate) {
            if (is_array($candidate) && ($candidate['n'] ?? null) === $supermarket) {
                /** @var array<string, mixed> $candidate */
                return $candidate;
            }
        }

        return null;
    }
}
