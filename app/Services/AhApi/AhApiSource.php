<?php declare(strict_types=1);

namespace App\Services\AhApi;

use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopSnapshot;
use App\Services\Checkjebon\CheckjebonResult;
use App\Support\UrlNormalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Live price source for ah.nl via Albert Heijn's mobile API. The website
 * blocks scrapers (Akamai) and the checkjebon dataset only carries the
 * regular price — this API returns the current price INCLUDING bonus.
 *
 * Unofficial but community-documented (bartmachielsen/SupermarktConnector):
 * an anonymous token (no account) authorizes the product endpoints. On any
 * API failure the caller falls back to the checkjebon dataset row, so a
 * broken API degrades to regular prices instead of dead shops.
 */
final readonly class AhApiSource
{
    private const string TOKEN_URL = 'https://api.ah.nl/mobile-auth/v1/auth/token/anonymous';

    private const string DETAIL_URL = 'https://api.ah.nl/mobile-services/product/detail/v4/fir/%s';

    private const string USER_AGENT = 'Appie/8.22.3';

    private const string TOKEN_CACHE_KEY = 'dipcatch:ah-api:token';

    public function supports(string $host): bool
    {
        return $host === 'ah.nl' || str_ends_with($host, '.ah.nl');
    }

    public function resolve(string $normalizedUrl): CheckjebonResult
    {
        $productId = self::productIdFromUrl($normalizedUrl);
        if ($productId === null) {
            return CheckjebonResult::miss(CheckjebonResult::REASON_UNRECOGNIZED_URL);
        }

        $token = $this->token();
        if ($token === null) {
            return CheckjebonResult::miss(CheckjebonResult::REASON_API_ERROR);
        }

        try {
            $response = Http::withToken($token)
                ->withHeaders(['User-Agent' => self::USER_AGENT, 'X-Application' => 'AHWEBSHOP'])
                ->timeout(15)
                ->get(sprintf(self::DETAIL_URL, $productId));
        } catch (ConnectionException $e) {
            Log::warning('AH API detail fetch failed.', ['product_id' => $productId, 'error' => $e->getMessage()]);

            return CheckjebonResult::miss(CheckjebonResult::REASON_API_ERROR);
        }

        if ($response->status() === 401) {
            // Token expired early — drop it so the next check re-authenticates.
            Cache::forget(self::TOKEN_CACHE_KEY);

            return CheckjebonResult::miss(CheckjebonResult::REASON_API_ERROR);
        }

        if ($response->status() === 404) {
            return CheckjebonResult::miss(CheckjebonResult::REASON_NOT_IN_DATASET);
        }

        if (! $response->successful()) {
            Log::warning('AH API detail returned an error.', ['product_id' => $productId, 'status' => $response->status()]);

            return CheckjebonResult::miss(CheckjebonResult::REASON_API_ERROR);
        }

        /** @var array<string, mixed> $payload */
        $payload = (array) $response->json();

        $snapshot = self::snapshotFrom($payload);
        if ($snapshot === null) {
            Log::warning('AH API detail had no parseable price.', ['product_id' => $productId]);

            return CheckjebonResult::miss(CheckjebonResult::REASON_API_ERROR);
        }

        return CheckjebonResult::found($snapshot);
    }

    private function token(): ?string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(15)
                ->post(self::TOKEN_URL, ['clientId' => 'appie']);
        } catch (ConnectionException $e) {
            Log::warning('AH API anonymous token request failed.', ['error' => $e->getMessage()]);

            return null;
        }

        $token = $response->json('access_token');
        $expiresIn = $response->json('expires_in');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            Log::warning('AH API anonymous token response unusable.', ['status' => $response->status()]);

            return null;
        }

        $ttl = is_int($expiresIn) ? max(60, $expiresIn - 3600) : 3600;
        Cache::put(self::TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
    }

    /**
     * Reads the `productCard` from the detail response (shape verified live
     * on 2026-08-31). `currentPrice` is bonus-aware — for a bonus product it
     * carries the discounted price while `priceBeforeBonus` holds the
     * regular one.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function snapshotFrom(array $payload): ?ShopSnapshot
    {
        $card = is_array($payload['productCard'] ?? null) ? $payload['productCard'] : $payload;

        $price = PriceNormalizer::fromMixed(
            data_get($card, 'currentPrice') ?? data_get($card, 'priceBeforeBonus'),
        );

        if ($price === null) {
            return null;
        }

        $title = data_get($card, 'title');
        $image = data_get($card, 'images.0.url');
        $orderable = data_get($card, 'orderAvailabilityStatus');

        return new ShopSnapshot(
            title: is_string($title) && $title !== '' ? $title : 'AH product',
            imageUrl: is_string($image) ? $image : null,
            price: $price,
            currency: 'EUR',
            inStock: ! (is_string($orderable) && $orderable === 'UNAVAILABLE'),
            raw: [
                'source' => 'ah-api',
                'is_bonus' => (bool) data_get($card, 'isBonus'),
                'price_before_bonus' => data_get($card, 'priceBeforeBonus'),
            ],
        );
    }

    private static function productIdFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path)) {
            return null;
        }

        foreach (explode('/', $path) as $segment) {
            // The web URL carries `wi526381`; the API wants the bare number.
            if (preg_match('/^wi(\d+)$/i', $segment, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    public static function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? UrlNormalizer::normalizeHost($host) : null;
    }
}
