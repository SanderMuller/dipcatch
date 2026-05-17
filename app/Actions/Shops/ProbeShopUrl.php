<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\AdapterResolver;
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
 * Sync probe used by the Add-Shop Livewire form: normalize → dedupe →
 * per-user rate-limit → fetch → resolve adapter → currency check. Never
 * persists; the Livewire component does that after Confirm.
 */
final readonly class ProbeShopUrl
{
    private const int PER_USER_LIMIT_PER_MIN = 6;

    public function __construct(
        private ShopFetcher $fetcher,
        private AdapterResolver $resolver,
    ) {}

    /**
     * @param  array{price?: ?string, title?: ?string, image?: ?string}  $selectors
     */
    public function __invoke(
        Product $product,
        string $rawUrl,
        User $actor,
        array $selectors = [],
        ?string $manualCurrency = null,
        ?string $variantKey = null,
    ): ProbeOutcome {
        try {
            $normalizedUrl = UrlNormalizer::normalize($rawUrl);
        } catch (InvalidArgumentException) {
            return ProbeOutcome::failed('invalid_url');
        }

        $urlHash = UrlNormalizer::hash($normalizedUrl);
        $existing = $product->shops()->where('url_hash', $urlHash)->first();
        if ($existing instanceof Shop) {
            return ProbeOutcome::duplicate($existing);
        }

        if (! $this->withinPerUserLimit($actor)) {
            return ProbeOutcome::failed('probe_rate_limited');
        }

        try {
            $fetch = $this->fetcher->fetch($normalizedUrl);
        } catch (RobotsDisallowed) {
            return ProbeOutcome::failed('robots_disallowed');
        } catch (Blocked) {
            return ProbeOutcome::failed('blocked');
        } catch (RateLimitedByHost $e) {
            return ProbeOutcome::failed(
                $e->source === RateLimitedByHost::SOURCE_LOCAL ? 'local_throttle' : 'host_rate_limited',
                ['retry_after_seconds' => $e->retryAfterSeconds],
            );
        } catch (TemporaryFailure $e) {
            return ProbeOutcome::failed('temporary_failure', ['status' => $e->statusCode]);
        } catch (HttpError $e) {
            return ProbeOutcome::failed('http_error', ['status' => $e->statusCode]);
        }

        $context = new AdapterContext(
            selectors: $selectors,
            fallbackCurrency: $manualCurrency ?? $product->currency,
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
            return ProbeOutcome::failed(
                $extraction->failureReason ?? 'extraction_failed',
            );
        }

        $snapshot = $extraction->snapshot;
        assert($snapshot !== null);

        if (strcasecmp($snapshot->currency, $product->currency) !== 0) {
            return ProbeOutcome::failed('currency_mismatch', [
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

    private function withinPerUserLimit(User $user): bool
    {
        $key = "dipcatch:probe:user:{$user->id}";
        if (RateLimiter::tooManyAttempts($key, self::PER_USER_LIMIT_PER_MIN)) {
            return false;
        }
        RateLimiter::hit($key, decaySeconds: 60);

        return true;
    }
}
