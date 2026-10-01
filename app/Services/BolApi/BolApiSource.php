<?php declare(strict_types=1);

namespace App\Services\BolApi;

use App\PriceAdapters\ShopSnapshot;
use App\Services\Checkjebon\CheckjebonResult;
use App\Support\Gtin;
use App\Support\UrlNormalizer;

/**
 * Price source for bol.com product pages through bol's Catalog API, so a
 * bol.com shop is read even when the website blocks automated requests.
 * The product id in the page URL is turned into its barcode, and the
 * barcode gives the best offer in the Netherlands. A miss lets the caller
 * fall back to reading the page.
 */
final readonly class BolApiSource
{
    public function __construct(private BolCatalogClient $bol) {}

    public function supports(string $host): bool
    {
        return BolCatalogClient::configured() && UrlNormalizer::normalizeHost($host) === 'bol.com';
    }

    public function resolve(string $normalizedUrl): CheckjebonResult
    {
        $productId = self::productIdOf($normalizedUrl);

        if ($productId === null) {
            return CheckjebonResult::miss(CheckjebonResult::REASON_UNRECOGNIZED_URL);
        }

        try {
            $ean = $this->bol->eanOf($productId);
            $offer = $ean === null ? null : $this->bol->findByEan($ean);
        } catch (BolApiFailed) {
            return CheckjebonResult::miss(CheckjebonResult::REASON_API_ERROR);
        }

        if (! $offer instanceof BolProduct || $offer->price === null) {
            return CheckjebonResult::miss(CheckjebonResult::REASON_NOT_IN_DATASET);
        }

        return CheckjebonResult::found(new ShopSnapshot(
            title: $offer->title,
            imageUrl: $offer->imageUrl,
            price: $offer->price,
            currency: 'EUR',
            inStock: true,
            raw: ['source' => 'bol-api'],
            gtin: Gtin::normalize($offer->ean),
            gtinAuthoritative: true,
            claimedRegularPrice: $offer->strikethroughPrice,
            claimAuthoritative: true,
        ));
    }

    /** The bol product id: the last all-digit segment of a /p/ page path. */
    private static function productIdOf(string $url): ?string
    {
        $segments = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH))));

        if (! in_array('p', $segments, strict: true)) {
            return null;
        }

        $id = array_find(array_reverse($segments), static fn (string $segment): bool => ctype_digit($segment));

        return is_string($id) ? $id : null;
    }
}
