<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Enums\ProbeFailure;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\AdapterResolver;
use App\PriceAdapters\ShopSnapshot;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\ShopFetcher\Exceptions\Blocked;
use App\Services\ShopFetcher\Exceptions\HttpError;
use App\Services\ShopFetcher\Exceptions\RateLimitedByHost;
use App\Services\ShopFetcher\Exceptions\RobotsDisallowed;
use App\Services\ShopFetcher\Exceptions\TemporaryFailure;
use App\Services\ShopFetcher\ShopFetcher;
use App\Support\UrlNormalizer;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * Sync probe used by the Add-Shop and Create-Product-From-URL Livewire
 * forms: normalize → dedupe → dataset short-circuit (checkjebon hosts) →
 * per-user rate-limit → fetch → resolve adapter → currency check. Never persists; the Livewire component does
 * that after Confirm. With a null product (create mode) the per-product
 * dedupe and currency-mismatch checks are skipped — the probed currency
 * defines the product currency.
 */
final readonly class ProbeShopUrl
{
    private const int PER_USER_LIMIT_PER_MIN = 6;

    public function __construct(
        private ShopFetcher $fetcher,
        private AdapterResolver $resolver,
        private CheckjebonSource $checkjebon,
        private AhApiSource $ahApi,
    ) {}

    /**
     * @param  array{price?: ?string, title?: ?string, image?: ?string}  $selectors
     */
    public function __invoke(
        ?Product $product,
        string $rawUrl,
        User $actor,
        array $selectors = [],
        ?string $manualCurrency = null,
        ?string $variantKey = null,
    ): ProbeOutcome {
        try {
            $normalizedUrl = UrlNormalizer::normalize($rawUrl);
        } catch (InvalidArgumentException) {
            return ProbeOutcome::failed(ProbeFailure::InvalidUrl);
        }

        if ($product instanceof Product) {
            $urlHash = UrlNormalizer::hash($normalizedUrl);
            $existing = $product->shops()->where('url_hash', $urlHash)->first();
            if ($existing instanceof Shop) {
                return ProbeOutcome::duplicate($existing);
            }
        }

        // Dataset-served hosts resolve locally: no network fetch, and no
        // per-user probe budget consumed (bulk-adding must not throttle).
        $host = UrlNormalizer::normalizeHost((string) parse_url($normalizedUrl, PHP_URL_HOST));

        $local = $this->resolveFromLocalSources($product, $normalizedUrl, $host);
        if ($local instanceof ProbeOutcome) {
            return $local;
        }

        if (! $this->withinPerUserLimit($actor)) {
            return ProbeOutcome::failed(ProbeFailure::ProbeRateLimited);
        }

        try {
            $fetch = $this->fetcher->fetch($normalizedUrl);
        } catch (RobotsDisallowed) {
            return ProbeOutcome::failed(ProbeFailure::RobotsDisallowed);
        } catch (Blocked) {
            return ProbeOutcome::failed(ProbeFailure::Blocked);
        } catch (RateLimitedByHost $e) {
            return ProbeOutcome::failed(
                $e->source === RateLimitedByHost::SOURCE_LOCAL
                    ? ProbeFailure::LocalThrottle
                    : ProbeFailure::HostRateLimited,
                ['retry_after_seconds' => $e->retryAfterSeconds],
            );
        } catch (TemporaryFailure $e) {
            return ProbeOutcome::failed(ProbeFailure::TemporaryFailure, ['status' => $e->statusCode]);
        } catch (HttpError $e) {
            return ProbeOutcome::failed(ProbeFailure::HttpError, ['status' => $e->statusCode]);
        }

        $context = new AdapterContext(
            selectors: $selectors,
            fallbackCurrency: $manualCurrency ?? $product->currency ?? 'EUR',
            variantKey: $variantKey,
        );

        $extraction = $this->resolver->resolve(
            url: $fetch->finalUrl,
            html: $fetch->html,
            context: $context,
        );

        if ($extraction->isAmbiguous()) {
            return ProbeOutcome::ambiguous(
                variants: $extraction->variants,
                normalizedUrl: $normalizedUrl,
                host: $fetch->host,
            );
        }

        if (! $extraction->isSuccess()) {
            return ProbeOutcome::extractionFailed($extraction->failureReason);
        }

        $snapshot = $extraction->snapshot;
        assert($snapshot !== null);

        if ($product instanceof Product && strcasecmp($snapshot->currency, $product->currency) !== 0) {
            return ProbeOutcome::failed(ProbeFailure::CurrencyMismatch, [
                'expected' => $product->currency,
                'actual' => $snapshot->currency,
            ]);
        }

        return ProbeOutcome::success(
            snapshot: $snapshot,
            normalizedUrl: $normalizedUrl,
            host: $fetch->host,
            adapterKey: $extraction->adapterKey ?? 'generic',
        );
    }

    /**
     * ah.nl resolves via the mobile API (live, bonus-aware) with the
     * checkjebon dataset as a regular-price fallback when the unofficial
     * API misbehaves; boodschaapje.nl/Lidl is dataset-only. Returns null
     * for every other host so the network probe runs.
     */
    private function resolveFromLocalSources(?Product $product, string $normalizedUrl, string $host): ?ProbeOutcome
    {
        if ($this->ahApi->supports($host)) {
            $result = $this->ahApi->resolve($normalizedUrl);
            $snapshot = $result->snapshot;
            if ($snapshot instanceof ShopSnapshot) {
                return $this->successFromSnapshot($product, $snapshot, $normalizedUrl, $host, adapterKey: 'ah-api');
            }
        }

        if ($this->checkjebon->supports($host)) {
            return $this->resolveFromCheckjebon($product, $normalizedUrl, $host);
        }

        return null;
    }

    private function resolveFromCheckjebon(?Product $product, string $normalizedUrl, string $host): ProbeOutcome
    {
        $result = $this->checkjebon->resolve($normalizedUrl);

        if (! $result->isFound()) {
            return ProbeOutcome::failed(ProbeFailure::NotInDataset, ['reason' => $result->missReason]);
        }

        $snapshot = $result->snapshot;
        assert($snapshot instanceof ShopSnapshot);

        return $this->successFromSnapshot($product, $snapshot, $normalizedUrl, $host, adapterKey: 'checkjebon');
    }

    private function successFromSnapshot(
        ?Product $product,
        ShopSnapshot $snapshot,
        string $normalizedUrl,
        string $host,
        string $adapterKey,
    ): ProbeOutcome {
        if ($product instanceof Product && strcasecmp($snapshot->currency, $product->currency) !== 0) {
            return ProbeOutcome::failed(ProbeFailure::CurrencyMismatch, [
                'expected' => $product->currency,
                'actual' => $snapshot->currency,
            ]);
        }

        return ProbeOutcome::success(
            snapshot: $snapshot,
            normalizedUrl: $normalizedUrl,
            host: $host,
            adapterKey: $adapterKey,
        );
    }

    private function withinPerUserLimit(User $user): bool
    {
        $key = "dipcatch:probe:user:{$user->id}";
        if (RateLimiter::tooManyAttempts($key, self::PER_USER_LIMIT_PER_MIN)) {
            return false;
        }
        RateLimiter::hit($key);

        return true;
    }
}
