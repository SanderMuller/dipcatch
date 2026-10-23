<?php declare(strict_types=1);

namespace App\Actions\Products;

use App\Models\CheckjebonChain;
use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\AdapterResolver;
use App\Services\PriceSources\ApiPriceSources;
use App\Services\ShopFetcher\Exceptions\FetchException;
use App\Services\ShopFetcher\Exceptions\RateLimitedByHost;
use App\Services\ShopFetcher\Exceptions\TemporaryFailure;
use App\Services\ShopFetcher\ShopFetcher;
use App\Support\ImageUrl;
use App\Support\SupermarketChains;
use App\Support\UrlNormalizer;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * The photo of a supermarket page a name search listed without one: the
 * catalogue carries no photos. Read the way a probe reads the page, kept per
 * page, and never recorded as the host's health.
 *
 * Only a supermarket DipCatch can track qualifies, so this never becomes a
 * fetch of an address someone else picked.
 */
final readonly class FindCataloguePhoto
{
    private const int HIT_DAYS = 30;

    /** A page without a photo, or a shop that refused it, is not asked again before this. */
    private const int MISS_HOURS = 24;

    /** An API that answered nothing may have failed for a moment. */
    private const int API_MISS_MINUTES = 60;

    /**
     * The share of a host's per-minute fetches a photo may leave unused:
     * price checks and the user's own pick share that ceiling, so a photo
     * waits rather than take the last of it.
     */
    private const float RESERVE_SHARE = 0.5;

    public function __construct(
        private ShopFetcher $fetcher,
        private AdapterResolver $resolver,
        private ApiPriceSources $apiSources,
    ) {}

    /** Whether the link points at a supermarket the name search lists. */
    public function covers(string $url): bool
    {
        $host = self::hostOf($url);

        if ($host === null) {
            return false;
        }

        foreach (CheckjebonChain::freshByKey() as $chain) {
            if (SupermarketChains::isTrackable($chain->chain) && in_array($host, SupermarketChains::hosts($chain->chain, $chain->base_url), strict: true)) {
                return true;
            }
        }

        return false;
    }

    public function __invoke(string $url): ?string
    {
        try {
            $normalized = UrlNormalizer::normalize($url);
        } catch (InvalidArgumentException) {
            return null;
        }

        $host = (string) self::hostOf($normalized);
        $cacheKey = 'name-search:photo:' . UrlNormalizer::hash($normalized);
        $cached = Cache::get($cacheKey);

        if (is_string($cached)) {
            return $cached === '' ? null : $cached;
        }

        // Not kept: the next search may find the host with room again.
        if (! self::hostHasRoom($host)) {
            return null;
        }

        [$photo, $keepFor] = $this->read($normalized, $host);

        if ($photo !== null) {
            Cache::put($cacheKey, $photo, now()->addDays(self::HIT_DAYS));
        } elseif ($keepFor !== null) {
            Cache::put($cacheKey, '', $keepFor);
        }

        return $photo;
    }

    /**
     * @return array{0: ?string, 1: ?CarbonInterface}  The photo, and until when a miss is kept; null keeps nothing.
     */
    private function read(string $url, string $host): array
    {
        if ($this->apiSources->supports($host)) {
            $reading = $this->apiSources->read($host, $url);

            return [ImageUrl::safe($reading[0]->imageUrl ?? null), now()->addMinutes(self::API_MISS_MINUTES)];
        }

        try {
            $fetch = $this->fetcher->fetch($url, rememberHost: false);
        } catch (RateLimitedByHost|TemporaryFailure) {
            return [null, null];
        } catch (FetchException) {
            return [null, now()->addHours(self::MISS_HOURS)];
        }

        $extraction = $this->resolver->resolve(url: $fetch->finalUrl, html: $fetch->html, context: new AdapterContext(fallbackCurrency: 'EUR'));

        return [ImageUrl::safe($extraction->snapshot?->imageUrl), now()->addHours(self::MISS_HOURS)];
    }

    private static function hostHasRoom(string $host): bool
    {
        $limit = Config::integer('dipcatch.fetcher.rate_limit_per_minute');

        return RateLimiter::remaining(ShopFetcher::throttleKey($host), $limit) > $limit * self::RESERVE_SHARE;
    }

    private static function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? UrlNormalizer::normalizeHost($host) : null;
    }
}
