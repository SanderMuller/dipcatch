<?php declare(strict_types=1);

namespace App\Services\DmApi;

use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopSnapshot;
use App\Services\Checkjebon\CheckjebonResult;
use App\Support\Gtin;
use App\Support\UrlNormalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Price source for dm.de and dm.at product pages. The pages render in the
 * browser and serve no price, but the product API the page itself calls
 * answers with the price, the title and the barcode for the dm article
 * number in the page URL (`/p/d/{dan}/…`). A miss lets the caller fall
 * back to reading the page.
 */
final readonly class DmApiSource
{
    private const string DETAIL_URL = 'https://products.dm.de/product/products/detail/%s/dan/%s';

    private const array COUNTRIES = ['dm.de' => 'DE', 'dm.at' => 'AT'];

    public function supports(string $host): bool
    {
        return isset(self::COUNTRIES[UrlNormalizer::normalizeHost($host)]);
    }

    public function resolve(string $normalizedUrl): CheckjebonResult
    {
        $country = self::COUNTRIES[UrlNormalizer::normalizeHost((string) parse_url($normalizedUrl, PHP_URL_HOST))] ?? null;
        $dan = self::articleNumberOf($normalizedUrl);

        if ($country === null || $dan === null) {
            return CheckjebonResult::miss(CheckjebonResult::REASON_UNRECOGNIZED_URL);
        }

        try {
            $response = Http::withHeaders(['User-Agent' => Config::string('dipcatch.fetcher.user_agent')])
                ->acceptJson()
                ->timeout(15)
                ->get(sprintf(self::DETAIL_URL, $country, $dan));
        } catch (ConnectionException $e) {
            Log::warning('dm product API request failed.', ['dan' => $dan, 'error' => $e->getMessage()]);

            return CheckjebonResult::miss(CheckjebonResult::REASON_API_ERROR);
        }

        if ($response->status() === 404) {
            return CheckjebonResult::miss(CheckjebonResult::REASON_NOT_IN_DATASET);
        }

        $product = $response->successful() ? $response->json() : null;

        if (! is_array($product)) {
            return CheckjebonResult::miss(CheckjebonResult::REASON_API_ERROR);
        }

        $snapshot = self::snapshot($product);

        return $snapshot === null
            ? CheckjebonResult::miss(CheckjebonResult::REASON_NOT_IN_DATASET)
            : CheckjebonResult::found($snapshot);
    }

    /**
     * The page's own structured data, as the API hands it to the page: a
     * price in euros as a number, and the name without the brand.
     *
     * @param  array<mixed>  $product
     */
    private static function snapshot(array $product): ?ShopSnapshot
    {
        $data = $product['seoInformation']['structuredData'] ?? null;

        if (! is_array($data)) {
            return null;
        }

        $price = is_int($data['price'] ?? null) || is_float($data['price'] ?? null) ? PriceNormalizer::fromMixed($data['price']) : null;
        $currency = is_string($data['priceCurrency'] ?? null) ? strtoupper($data['priceCurrency']) : null;
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';

        if ($price === null || (float) $price <= 0 || $currency === null || $name === '') {
            return null;
        }

        $brand = is_string($data['brand'] ?? null) ? trim($data['brand']) : '';
        $gtin = is_string($data['gtin'] ?? null) ? Gtin::normalize($data['gtin']) : null;

        return new ShopSnapshot(
            title: $brand === '' || str_starts_with($name, $brand) ? $name : $brand . ' ' . $name,
            imageUrl: is_string($data['image'] ?? null) ? $data['image'] : null,
            price: $price,
            currency: $currency,
            // The API states no stock, and a guess is not a stock state.
            inStock: null,
            raw: ['source' => 'dm-api'],
            gtin: $gtin,
            gtinAuthoritative: true,
        );
    }

    /** The dm article number: the all-digit segment after `/p/d/`. */
    private static function articleNumberOf(string $url): ?string
    {
        $segments = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH)), static fn (string $s): bool => $s !== ''));

        if (($segments[0] ?? null) !== 'p' || ($segments[1] ?? null) !== 'd') {
            return null;
        }

        $dan = $segments[2] ?? '';

        return preg_match('/^\d+$/', $dan) === 1 ? $dan : null;
    }
}
