<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CanaryOutcome;
use App\Models\AdapterCanaryResult;
use App\PriceAdapters\AdapterResolver;
use App\PriceAdapters\ExtractionResult;
use App\Services\ShopFetcher\Exceptions\FetchException;
use App\Services\ShopFetcher\Exceptions\HttpError;
use App\Services\ShopFetcher\Exceptions\RobotsDisallowed;
use App\Services\ShopFetcher\ShopFetcher;
use App\Support\CanaryEntries;
use App\Support\Numeric;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Throwable;

/**
 * Fetch one known product page per host adapter and report whether that
 * adapter still reads it.
 *
 * Two adapter failures exist and the application sees neither on its own. One
 * breaks loudly — a matched host adapter returns `failed` after a redesign,
 * which stores a ParseError on the shops that use it, and no shop uses an
 * adapter nobody tracks. The other breaks quietly — the adapter still finds a
 * number and the number is wrong. The price-move arm is the only thing that
 * can see the second one.
 *
 * Nothing here writes a price_check, touches a shop, or influences an alert.
 */
#[Signature('dipcatch:canary {--force : Fetch even outside the configured environments}')]
#[Description('Check that every host price adapter still reads a known product page.')]
final class RunAdapterCanaryCommand extends Command
{
    private const int BC_SCALE = 4;

    public function handle(ShopFetcher $fetcher, AdapterResolver $resolver): int
    {
        $configured = CanaryEntries::configured();

        // Before the empty-list return: the last adapter leaving the list
        // must take its row with it, or a retired entry reports forever.
        AdapterCanaryResult::query()
            ->whereNotIn(AdapterCanaryResult::ADAPTER, array_keys($configured))
            ->delete();

        if ($configured === []) {
            $this->components->warn('No canary URLs are configured.');

            return self::SUCCESS;
        }

        if (! $this->mayFetch()) {
            $this->components->warn('The canary does not fetch in this environment. Pass --force to override.');

            return self::SUCCESS;
        }

        foreach ($configured as $adapter => $url) {
            $this->runOne($fetcher, $resolver, $adapter, $url);
        }

        return self::SUCCESS;
    }

    private function mayFetch(): bool
    {
        // `--force` is for a person checking a new adapter by hand. It cannot
        // reach the test suite: a test that fetched twenty-one real shops
        // would be a slow, flaky way to annoy every one of them.
        if ($this->option('force') === true && ! app()->environment('testing')) {
            return true;
        }

        /** @var list<string> $environments */
        $environments = Config::array('canary.environments');

        return app()->environment($environments);
    }

    private function runOne(ShopFetcher $fetcher, AdapterResolver $resolver, string $adapter, string $url): void
    {
        $existing = AdapterCanaryResult::query()
            ->where(AdapterCanaryResult::ADAPTER, $adapter)
            ->first();

        // A repointed entry is a different product. Measuring it against the
        // old product's price would report rot on the first run after an edit.
        $baseline = $existing instanceof AdapterCanaryResult && $existing->url === $url
            ? $existing->last_ok_price
            : null;

        $result = $this->observe($fetcher, $resolver, $adapter, $url, $baseline === null ? null : (string) $baseline);

        $this->store($adapter, $url, $existing, $result);

        $this->components->twoColumnDetail($adapter, $result['outcome']->value);
    }

    /**
     * @return array{outcome: CanaryOutcome, observed: ?string, price: ?string, detail: ?string}
     */
    private function observe(ShopFetcher $fetcher, AdapterResolver $resolver, string $adapter, string $url, ?string $baseline): array
    {
        try {
            // `rememberHost: false`: canary traffic must not rewrite the host
            // failure memory that real user probes read.
            $fetch = $fetcher->fetch($url, rememberHost: false);
        } catch (RobotsDisallowed $e) {
            // Never resolves on its own — the entry needs a different URL.
            return $this->outcome(CanaryOutcome::BadUrl, detail: $e->getMessage());
        } catch (HttpError $e) {
            return $this->fromHttpError($e);
        } catch (FetchException $e) {
            return $this->outcome(CanaryOutcome::Unreachable, detail: $e->getMessage());
        } catch (InvalidArgumentException $e) {
            // A malformed URL or a non-http scheme: the entry itself is wrong.
            return $this->outcome(CanaryOutcome::BadUrl, detail: $e->getMessage());
        }

        try {
            $extraction = $resolver->resolve(url: $fetch->finalUrl, html: $fetch->html);
        } catch (Throwable $e) {
            // An adapter that throws cannot read its page, which is what this
            // command exists to report.
            return $this->outcome(CanaryOutcome::Rot, detail: $e::class . ': ' . $e->getMessage());
        }

        return $this->judge($extraction, $adapter, $fetch->finalUrl, $url, $baseline);
    }

    /**
     * @return array{outcome: CanaryOutcome, observed: ?string, price: ?string, detail: ?string}
     */
    private function fromHttpError(HttpError $e): array
    {
        if ($e->statusCode === 404 || $e->statusCode === 410) {
            return $this->outcome(CanaryOutcome::BadUrl, detail: 'HTTP ' . $e->statusCode);
        }

        // Status 0 covers a URL-safety refusal, a DNS failure, a TLS fault and
        // any other unexpected throwable alike, so it cannot be read as
        // evidence that the URL is wrong. A genuinely unusable URL stays
        // unreachable every run, and the health check escalates that.
        return $this->outcome(CanaryOutcome::Unreachable, detail: 'HTTP ' . $e->statusCode);
    }

    /**
     * @return array{outcome: CanaryOutcome, observed: ?string, price: ?string, detail: ?string}
     */
    private function judge(ExtractionResult $extraction, string $adapter, string $finalUrl, string $requestedUrl, ?string $baseline): array
    {
        $observed = $extraction->adapterKey;
        $landed = $finalUrl === $requestedUrl ? null : 'landed on ' . $finalUrl;

        if (! $extraction->isSuccess()) {
            return $this->outcome(
                CanaryOutcome::Rot,
                observed: $observed,
                detail: $this->join($extraction->failureReason ?? 'extraction failed', $landed),
            );
        }

        // A cheap invariant. It catches a host map typo or an adapter dropped
        // from the chain, not a redesign — a matched host adapter fails rather
        // than letting a weaker one claim its page.
        if ($observed !== $adapter) {
            return $this->outcome(
                CanaryOutcome::Rot,
                $observed,
                detail: $this->join("expected adapter {$adapter}, got " . ($observed ?? 'none'), $landed),
            );
        }

        $price = $extraction->snapshot?->price;

        if ($price === null) {
            return $this->outcome(CanaryOutcome::Rot, $observed, detail: $this->join('no price', $landed));
        }

        // `PriceNormalizer` accepts any numeric string, so zero and negatives
        // reach here. A stored zero would also divide by zero next run.
        if (bccomp(Numeric::str($price), '0', self::BC_SCALE) <= 0) {
            return $this->outcome(CanaryOutcome::Rot, $observed, $price, $this->join('price is not positive', $landed));
        }

        if ($baseline !== null && $this->movedTooFar($price, $baseline)) {
            return $this->outcome(
                CanaryOutcome::Rot,
                $observed,
                $price,
                $this->join("price moved from {$baseline} to {$price}", $landed),
            );
        }

        return $this->outcome(CanaryOutcome::Ok, $observed, $price, $landed);
    }

    /**
     * A two-sided relative change: an adapter that starts reading a higher
     * wrong number is as broken as one that reads a lower wrong number, so
     * this is not the drop engine's one-directional comparison.
     */
    private function movedTooFar(string $price, string $baseline): bool
    {
        if (bccomp(Numeric::str($baseline), '0', self::BC_SCALE) <= 0) {
            return false;
        }

        $delta = bcsub(Numeric::str($price), Numeric::str($baseline), self::BC_SCALE);

        if (bccomp($delta, '0', self::BC_SCALE) < 0) {
            $delta = bcmul($delta, '-1', self::BC_SCALE);
        }

        $movedPct = bcmul(bcdiv($delta, Numeric::str($baseline), self::BC_SCALE), '100', self::BC_SCALE);

        return bccomp($movedPct, (string) Config::integer('canary.price_move_pct'), self::BC_SCALE) >= 0;
    }

    /**
     * @return array{outcome: CanaryOutcome, observed: ?string, price: ?string, detail: ?string}
     */
    private function outcome(CanaryOutcome $outcome, ?string $observed = null, ?string $price = null, ?string $detail = null): array
    {
        return ['outcome' => $outcome, 'observed' => $observed, 'price' => $price, 'detail' => $detail];
    }

    private function join(string $reason, ?string $landed): string
    {
        return $landed === null ? $reason : $reason . ' (' . $landed . ')';
    }

    /**
     * @param  array{outcome: CanaryOutcome, observed: ?string, price: ?string, detail: ?string}  $result
     */
    private function store(string $adapter, string $url, ?AdapterCanaryResult $existing, array $result): void
    {
        $outcome = $result['outcome'];
        $repointed = $existing !== null && $existing->url !== $url;

        $lastOkPrice = match (true) {
            $outcome === CanaryOutcome::Ok => $result['price'],
            // A repoint drops the baseline; every other non-ok outcome keeps
            // it, so a blocked run does not erase what a good run learned.
            $repointed => null,
            default => $existing?->last_ok_price === null ? null : (string) $existing->last_ok_price,
        };

        AdapterCanaryResult::query()->updateOrCreate(
            [AdapterCanaryResult::ADAPTER => $adapter],
            [
                AdapterCanaryResult::URL => $url,
                AdapterCanaryResult::OUTCOME => $outcome,
                AdapterCanaryResult::OBSERVED_ADAPTER => $result['observed'],
                AdapterCanaryResult::PRICE => $result['price'],
                AdapterCanaryResult::LAST_OK_PRICE => $lastOkPrice,
                // Only an unreachable run extends the streak. Every other
                // outcome means the canary reached the page and learned
                // something, so the streak is over.
                AdapterCanaryResult::CONSECUTIVE_UNREACHABLE => $outcome === CanaryOutcome::Unreachable
                    ? ($existing instanceof AdapterCanaryResult ? $existing->consecutive_unreachable + 1 : 1)
                    : 0,
                AdapterCanaryResult::DETAIL => $result['detail'],
                AdapterCanaryResult::CHECKED_AT => now(),
            ],
        );
    }
}
