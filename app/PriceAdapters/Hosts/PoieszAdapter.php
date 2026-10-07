<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\BundleOffer;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\OwnsHosts;
use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\PromotionWindow;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use App\Services\Poiesz\PoieszOffers;
use App\Support\Gtin;
use App\Support\Numeric;
use App\Support\NuxtData;

/**
 * Host-specific adapter for the Poiesz webshop. The page carries no JSON-LD,
 * microdata or price meta tags — everything lives in the Nuxt
 * `__NUXT_DATA__` payload, a flat array where object values are indices into
 * that same array. The product record holds `price`, `name`, `image`,
 * `packageDescription` and `ean` (observed 2026-09-01). A product on offer
 * has `promotion` true, its offer in `promotionLabel` ("aanbieding",
 * "1+1 gratis") and, for a price cut, the price it replaces as
 * `strikeThroughPrice` (observed 2026-10-04). The page states no offer
 * period; {@see PoieszOffers} reads it from the offers feed.
 *
 * The payload also carries recommended products, so the record is matched on
 * the id in the URL.
 */
final readonly class PoieszAdapter implements HostSpecificAdapter, OwnsHosts, ShopAdapter
{
    public function __construct(private PoieszOffers $offers) {}

    public function key(): string
    {
        return 'poiesz';
    }

    public function ownedHosts(): array
    {
        return ['poiesz-supermarkten.nl'];
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        if (! HostUrl::matchesAny($url, $this->ownedHosts())) {
            return ExtractionResult::skip();
        }

        $data = NuxtData::decode($html);

        if ($data === null) {
            return ExtractionResult::failed('poiesz_no_payload');
        }

        $productId = HostUrl::lastNumericSegment($url);

        if ($productId === null) {
            // Without an id there is nothing to match on, and the payload
            // carries recommended products with the same shape — guessing
            // would price a different article.
            return ExtractionResult::failed('poiesz_no_product_id');
        }

        $record = NuxtData::recordsFor($data, ['price', 'name', 'id'], 'id', $productId)[0] ?? null;

        if ($record === null) {
            return ExtractionResult::failed('poiesz_no_product');
        }

        $price = PriceNormalizer::fromMixed(NuxtData::value($data, $record, 'price'));

        if ($price === null) {
            return ExtractionResult::failed('poiesz_no_price');
        }

        // A payload without the offer fields says nothing about an offer, so
        // it must not clear a stored one.
        $statesOffer = array_key_exists('promotion', $record);
        $onOffer = NuxtData::value($data, $record, 'promotion') === true;
        $label = NuxtData::value($data, $record, 'promotionLabel');
        $label = $onOffer && is_string($label) && $label !== '' ? $label : null;
        $window = $onOffer ? $this->runningWindow($productId, $label) : null;
        $bundle = $label === null ? null : BundleOffer::fromLabel($label, $price);

        $title = NuxtData::value($data, $record, 'name');
        $image = NuxtData::value($data, $record, 'image');
        $packageDescription = NuxtData::value($data, $record, 'packageDescription');

        $content = self::content($data, $record);

        return ExtractionResult::success(new ShopSnapshot(
            title: is_string($title) && $title !== '' ? $title : 'Unknown',
            imageUrl: is_string($image) && $image !== '' ? $image : null,
            price: $price,
            currency: 'EUR',
            // The payload carries no availability flag; the webshop only
            // lists products it sells, so an extracted product is in stock.
            inStock: true,
            raw: ['source' => 'poiesz'],
            packSize: is_string($packageDescription) ? $packageDescription : null,
            packSizeAuthoritative: true,
            gtin: Gtin::normalize(NuxtData::value($data, $record, 'ean')),
            gtinAuthoritative: true,
            promotionWindow: $window,
            // An unknown period keeps the stored one, except under a bundle:
            // an ended period from an earlier offer would cancel the bundle
            // the page shows now.
            promotionWindowAuthoritative: $statesOffer && (! $onOffer || $window !== null || $bundle !== null),
            bundleOffer: $bundle,
            bundleOfferAuthoritative: $statesOffer && (! $onOffer || $label !== null),
            claimedRegularPrice: self::claimedRegularPrice($data, $record, $price),
            claimAuthoritative: array_key_exists('strikeThroughPrice', $record),
            altPackSizes: $content,
            // A content field in a unit code this reader does not know says
            // nothing, and must not clear what an earlier read stored.
            altPackSizesAuthoritative: array_key_exists('volumeCe', $record) && ($content !== [] || NuxtData::value($data, $record, 'volumeCe') === null),
        ));
    }

    /**
     * The pack's content as a number and a unit code apart from the
     * description: "20.00 Stuks" for Iglo fish fingers, with `volumeCe` 560
     * and `unitId` GR beside it. Codes verified live on 2026-10-07: GR, ML
     * and ST. Any other code states nothing.
     *
     * @param  list<mixed>  $data
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private static function content(array $data, array $record): array
    {
        $volume = NuxtData::value($data, $record, 'volumeCe');
        $unit = match (NuxtData::value($data, $record, 'unitId')) {
            'GR' => 'g',
            'ML' => 'ml',
            'ST' => 'stuks',
            default => null,
        };

        if (! is_numeric($volume) || (float) $volume <= 0 || $unit === null) {
            return [];
        }

        return [Numeric::trimmed(number_format((float) $volume, 3, '.', '')) . ' ' . $unit];
    }

    private function runningWindow(string $productId, ?string $label): ?PromotionWindow
    {
        $window = $this->offers->windowFor($productId, $label);

        return $window?->isRunning() === true ? $window : null;
    }

    /**
     * @param  list<mixed>  $data
     * @param  array<string, mixed>  $record
     */
    private static function claimedRegularPrice(array $data, array $record, string $price): ?string
    {
        $strikeThrough = PriceNormalizer::fromMixed(NuxtData::value($data, $record, 'strikeThroughPrice'));

        return $strikeThrough !== null && bccomp(Numeric::str($strikeThrough), Numeric::str($price), 2) > 0 ? $strikeThrough : null;
    }
}
