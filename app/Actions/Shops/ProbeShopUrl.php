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
use App\Services\ShopFetcher\Exceptions\NotServable;
use App\Services\ShopFetcher\Exceptions\RateLimitedByHost;
use App\Services\ShopFetcher\Exceptions\RobotsDisallowed;
use App\Services\ShopFetcher\Exceptions\TemporaryFailure;
use App\Services\ShopFetcher\HostFetchMemory;
use App\Services\ShopFetcher\ShopFetcher;
use App\Support\Iso4217;
use App\Support\UnservableShops;
use App\Support\UrlNormalizer;
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
    public function __construct(
        private ShopFetcher $fetcher,
        private AdapterResolver $resolver,
        private CheckjebonSource $checkjebon,
        private AhApiSource $ahApi,
        private HostFetchMemory $memory,
        private ProbeBudget $budget,
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

        $unservable = UnservableShops::reasonFor($host);
        if ($unservable !== null) {
            return ProbeOutcome::failed(ProbeFailure::ShopNotServable, ['reason' => $unservable]);
        }

        $local = $this->resolveFromLocalSources($product, $normalizedUrl, $host);
        if ($local instanceof ProbeOutcome) {
            return $local;
        }

        $probeRetryAfter = $this->budget->spend($actor);

        if ($probeRetryAfter !== null) {
            return ProbeOutcome::failed(ProbeFailure::ProbeRateLimited, ['retry_after_seconds' => $probeRetryAfter]);
        }

        try {
            $fetch = $this->fetcher->fetch($normalizedUrl);
        } catch (NotServable $e) {
            // Only reachable through a redirect: the pasted host passed the
            // pre-fetch check, the host that answered did not.
            return ProbeOutcome::failed(ProbeFailure::ShopNotServable, ['reason' => $e->reason]);
        } catch (RobotsDisallowed) {
            return ProbeOutcome::failed(ProbeFailure::RobotsDisallowed);
        } catch (Blocked) {
            return ProbeOutcome::failed(ProbeFailure::Blocked, $this->hostHistory($host, HostFetchMemory::KIND_BLOCKED));
        } catch (RateLimitedByHost $e) {
            return ProbeOutcome::failed(
                $e->source === RateLimitedByHost::SOURCE_LOCAL
                    ? ProbeFailure::LocalThrottle
                    : ProbeFailure::HostRateLimited,
                ['retry_after_seconds' => $e->retryAfterSeconds],
            );
        } catch (TemporaryFailure $e) {
            return ProbeOutcome::failed(
                ProbeFailure::TemporaryFailure,
                ['status' => $e->statusCode] + $this->hostHistory($host, HostFetchMemory::KIND_SILENT),
            );
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
                unmatchedVariantKey: $extraction->unmatchedVariantKey,
            );
        }

        if (! $extraction->isSuccess()) {
            return ProbeOutcome::extractionFailed($extraction->failureReason);
        }

        $snapshot = $extraction->snapshot;
        assert($snapshot !== null);

        $checked = $this->withStorableCurrency($snapshot, $product);

        if ($checked instanceof ProbeOutcome) {
            return $checked;
        }

        $snapshot = $checked;

        // The link must point at what the price refers to. A caller that
        // pastes a product page and pins a variant would otherwise store the
        // page, and the shopper who clicks it lands on the default pack at a
        // different price than the one they were quoted.
        $target = self::variantTarget($normalizedUrl, $variantKey);
        $duplicate = $target === $normalizedUrl ? null : $this->existingShopFor($product, $target);

        if ($duplicate instanceof ProbeOutcome) {
            return $duplicate;
        }

        return ProbeOutcome::success(
            snapshot: $snapshot,
            normalizedUrl: $target,
            host: $fetch->host,
            adapterKey: $extraction->adapterKey ?? 'generic',
        );
    }

    /**
     * The snapshot with a storable currency code, or the outcome that says
     * why it has none.
     *
     * Every currency column is `char(3)`. A page quoting `"Euro"` states no
     * code that can be stored, so it is refused here rather than at the
     * insert, where Postgres rejects the whole write. A page quoting
     * `" EUR "` states a code, padded — carried on unnormalized it reads as
     * a different currency from the product's own.
     */
    private function withStorableCurrency(ShopSnapshot $snapshot, ?Product $product): ShopSnapshot|ProbeOutcome
    {
        $currency = Iso4217::normalize($snapshot->currency);

        if ($currency === null) {
            return ProbeOutcome::extractionFailed('currency_not_a_code');
        }

        $snapshot = $snapshot->with(currency: $currency);

        if ($product instanceof Product && strcasecmp($currency, $product->currency) !== 0) {
            return ProbeOutcome::failed(ProbeFailure::CurrencyMismatch, [
                'expected' => $product->currency,
                'actual' => $currency,
            ]);
        }

        return $snapshot;
    }

    /**
     * A duplicate outcome when this product already tracks the resolved
     * target. Duplicates are decided on that target, not on the pasted
     * string: two people pasting the same page and choosing different
     * variants added two different things.
     */
    private function existingShopFor(?Product $product, string $target): ?ProbeOutcome
    {
        if (! $product instanceof Product) {
            return null;
        }

        $existing = $product->shops()->where('url_hash', UrlNormalizer::hash($target))->first();

        return $existing instanceof Shop ? ProbeOutcome::duplicate($existing) : null;
    }

    /**
     * The address that names the chosen variant, when the key is one. A key
     * that is a sku rather than a URL leaves the pasted address alone.
     */
    private static function variantTarget(string $normalizedUrl, ?string $variantKey): string
    {
        if ($variantKey === null || ! str_starts_with($variantKey, 'http')) {
            return $normalizedUrl;
        }

        try {
            $target = UrlNormalizer::normalize($variantKey);
        } catch (InvalidArgumentException) {
            return $normalizedUrl;
        }

        // Only within the shop we just read: a key naming another host is
        // not a variant of this page.
        $sameHost = UrlNormalizer::normalizeHost((string) parse_url($target, PHP_URL_HOST))
            === UrlNormalizer::normalizeHost((string) parse_url($normalizedUrl, PHP_URL_HOST));

        return $sameHost ? $target : $normalizedUrl;
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

    /**
     * What this host has done lately, so the caller is told whether another
     * attempt is worth making.
     *
     * @return array{failures: int, persistent: bool}
     */
    private function hostHistory(string $host, string $kind): array
    {
        return [
            'failures' => $this->memory->count($host, $kind),
            'persistent' => $this->memory->isPersistent($host, $kind),
        ];
    }
}
