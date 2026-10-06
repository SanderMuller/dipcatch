<?php declare(strict_types=1);

namespace App\Services\AxfoodApi;

use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopSnapshot;
use App\Services\Checkjebon\CheckjebonResult;
use App\Support\Gtin;
use App\Support\PackSize;
use App\Support\UrlNormalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Price source for willys.se and hemkop.se (Axfood). Their product pages
 * render in the browser and serve no price, but the product endpoint the
 * page calls, `/axfood/rest/p/{code}`, answers with it for the code at the
 * end of the page URL (`/produkt/…-101233933_ST`). robots.txt allows the
 * path (read 2026-10-06). A miss lets the caller fall back to the page.
 */
final readonly class AxfoodApiSource
{
    private const string PRODUCT_PATH = '/axfood/rest/p/%s';

    /** @var list<string> */
    private const array HOSTS = ['willys.se', 'hemkop.se'];

    public function supports(string $host): bool
    {
        return in_array(UrlNormalizer::normalizeHost($host), self::HOSTS, strict: true);
    }

    public function resolve(string $normalizedUrl): CheckjebonResult
    {
        $host = UrlNormalizer::normalizeHost((string) parse_url($normalizedUrl, PHP_URL_HOST));
        $code = self::productCodeOf($normalizedUrl);

        if (! in_array($host, self::HOSTS, strict: true) || $code === null) {
            return CheckjebonResult::miss(CheckjebonResult::REASON_UNRECOGNIZED_URL);
        }

        try {
            $response = Http::withHeaders(['User-Agent' => Config::string('dipcatch.fetcher.user_agent')])
                ->acceptJson()
                ->timeout(15)
                ->get('https://www.' . $host . sprintf(self::PRODUCT_PATH, $code));
        } catch (ConnectionException $e) {
            Log::warning('Axfood product API request failed.', ['code' => $code, 'error' => $e->getMessage()]);

            return CheckjebonResult::miss(CheckjebonResult::REASON_API_ERROR);
        }

        if ($response->status() === 404) {
            return CheckjebonResult::miss(CheckjebonResult::REASON_NOT_IN_DATASET);
        }

        $product = $response->successful() ? $response->json() : null;

        if (! is_array($product)) {
            return CheckjebonResult::miss(CheckjebonResult::REASON_API_ERROR);
        }

        $snapshot = self::snapshot($product, $code);

        return $snapshot === null
            ? CheckjebonResult::miss(CheckjebonResult::REASON_NOT_IN_DATASET)
            : CheckjebonResult::found($snapshot);
    }

    /**
     * @param  array<mixed>  $product
     */
    private static function snapshot(array $product, string $code): ?ShopSnapshot
    {
        $regular = $product['priceValue'] ?? null;
        $name = is_string($product['name'] ?? null) ? trim($product['name']) : '';

        if (! (is_int($regular) || is_float($regular)) || $regular <= 0 || $name === '') {
            return null;
        }

        $promotion = self::promotionPrice($product, $code, (float) $regular);
        $brand = is_string($product['manufacturer'] ?? null) ? trim($product['manufacturer']) : '';
        $volume = is_string($product['displayVolume'] ?? null) ? trim($product['displayVolume']) : '';
        $image = $product['image']['url'] ?? null;

        return new ShopSnapshot(
            title: trim(($brand === '' || str_contains($name, $brand) ? '' : $brand . ' ') . $name),
            imageUrl: is_string($image) ? $image : null,
            price: (string) PriceNormalizer::fromMixed($promotion ?? $regular),
            currency: 'SEK',
            inStock: ! (($product['outOfStock'] ?? false) === true),
            raw: ['source' => 'axfood-api'],
            packSize: $volume !== '' && PackSize::parse($volume) !== null ? $volume : null,
            gtin: is_string($product['ean'] ?? null) ? Gtin::normalize($product['ean']) : null,
            gtinAuthoritative: true,
            claimedRegularPrice: $promotion === null ? null : PriceNormalizer::fromMixed($regular),
            claimAuthoritative: true,
        );
    }

    /**
     * The price of a campaign anyone gets on one piece: a `GENERAL` campaign,
     * not a `LOYALTY` one for Willys Plus members, and not a multi-buy.
     *
     * @param  array<mixed>  $product
     */
    private static function promotionPrice(array $product, string $code, float $regular): ?float
    {
        foreach (is_array($product['potentialPromotions'] ?? null) ? $product['potentialPromotions'] : [] as $promotion) {
            $value = is_array($promotion) ? ($promotion['price']['value'] ?? null) : null;

            if (is_array($promotion)
                && ($promotion['campaignType'] ?? null) === 'GENERAL'
                && ($promotion['qualifyingCount'] ?? null) === 1
                && in_array($code, is_array($promotion['productCodes'] ?? null) ? $promotion['productCodes'] : [], strict: true)
                && (is_int($value) || is_float($value)) && $value > 0 && $value < $regular) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * The product code that ends the page path: `…-101233933_ST`. Only `_ST`,
     * a product sold by the piece: one sold by weight (`_KG`) states its
     * price per kilo, which is a rate, not a price to pay.
     */
    private static function productCodeOf(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('~^/produkt/[^/]*?-?(\d+_ST)/?$~', $path, $m) === 1 ? $m[1] : null;
    }
}
