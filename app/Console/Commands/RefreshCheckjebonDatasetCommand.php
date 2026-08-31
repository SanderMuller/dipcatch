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
#[Description('Refresh the local checkjebon.nl price dataset (AH, Dirk, Lidl) from the upstream daily JSON.')]
class RefreshCheckjebonDatasetCommand extends Command
{
    private const string DATASET_URL = 'https://raw.githubusercontent.com/supermarkt/checkjebon/main/data/supermarkets.json';

    /**
     * The upstream file is ~10.1 MB today; the guard only trips on runaway
     * growth or a corrupted response.
     */
    private const int MAX_BODY_BYTES = 50 * 1024 * 1024;

    private const int UPSERT_CHUNK = 1000;

    /** @var list<string> Dataset keys (`n`) this app tracks. */
    private const array SUPERMARKETS = ['ah', 'dirk', 'lidl'];

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

        foreach (self::SUPERMARKETS as $supermarket) {
            $rows = $this->rowsFor($decoded, $supermarket, $runStartedAt);

            if ($rows === []) {
                // An upstream scrape hiccup (like ALDI's standing 0 rows) must
                // not wipe local data — keep whatever the last good run stored.
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
                    ['name', 'price', 'size', 'refreshed_at'],
                );
            }

            $pruned = DB::table('checkjebon_prices')
                ->where('supermarket', $supermarket)
                ->where('refreshed_at', '<', $runStartedAt)
                ->delete();

            $this->info(sprintf('%s: %d rows upserted, %d delisted rows pruned.', $supermarket, count($rows), $pruned));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     * @return list<array{supermarket: string, external_id: string, name: string, price: string, size: ?string, refreshed_at: DateTimeInterface}>
     */
    private function rowsFor(array $decoded, string $supermarket, DateTimeInterface $refreshedAt): array
    {
        $entry = null;
        foreach ($decoded as $candidate) {
            if (is_array($candidate) && ($candidate['n'] ?? null) === $supermarket) {
                $entry = $candidate;

                break;
            }
        }

        $products = $entry['d'] ?? null;
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
                'refreshed_at' => $refreshedAt,
            ];
        }

        return array_values($rows);
    }

    /**
     * AH links look like `wi257/ah-kruiden-roomkaas` — the `wi` id is the
     * match key. Dirk and Lidl links are the bare numeric product id.
     */
    private function externalIdFromLink(string $supermarket, string $link): ?string
    {
        if ($supermarket === 'ah') {
            return preg_match('/^(wi\d+)\//i', $link, $m) === 1 ? strtolower($m[1]) : null;
        }

        return ctype_digit($link) ? $link : null;
    }
}
