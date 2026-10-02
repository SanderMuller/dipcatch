<?php declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CanaryOutcome;
use App\Models\AdapterCanaryResult;
use App\PriceAdapters\AdapterResolver;
use App\PriceAdapters\ExtractionResult;
use App\Services\ShopFetcher\Exceptions\FetchException;
use App\Services\ShopFetcher\Exceptions\HttpError;
use App\Services\ShopFetcher\Exceptions\RobotsDisallowed;
use App\Services\ShopFetcher\ShopFetcher;
use App\Support\Numeric;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Throwable;

/**
 * One canary entry: fetch the adapter's known product page and store
 * whether the adapter still reads it.
 *
 * One try: the next night is the retry.
 */
#[Tries(1)]
#[Timeout(60)]
final class RunAdapterCanary implements ShouldQueue
{
    use Queueable;

    private const int BC_SCALE = 4;

    public function __construct(
        public string $adapter,
        public string $url,
    ) {}

    public function handle(ShopFetcher $fetcher, AdapterResolver $resolver): void
    {
        $existing = AdapterCanaryResult::query()
            ->where(AdapterCanaryResult::ADAPTER, $this->adapter)
            ->first();

        // A repointed entry is a different product. Measuring it against the
        // old product's price would report rot on the first run after an edit.
        $baseline = $existing instanceof AdapterCanaryResult && $existing->url === $this->url
            ? $existing->last_ok_price
            : null;

        $result = $this->observe($fetcher, $resolver, $this->adapter, $this->url, $baseline === null ? null : (string) $baseline);

        $this->store($this->adapter, $this->url, $existing, $result);
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
