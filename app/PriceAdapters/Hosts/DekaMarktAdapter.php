<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use App\Support\NuxtData;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Host-specific adapter for dekamarkt.nl. Same platform as dirk.nl — a Nuxt
 * app whose `__NUXT_DATA__` payload is a flat array of devalue records — but
 * its JSON-LD carries only Organization and WebSite data, so price and title
 * both come from the payload (observed 2026-09-01).
 *
 * Two records matter, both keyed by `productId`: the product (headerText,
 * packaging, images) and the price (normalPrice, offerPrice and the offer
 * window). The site shows the offer price while that window is open — a
 * "1+1 GRATIS" product displays 1.59 with 1.95 struck through — so the
 * adapter reports the same number the shopper sees.
 *
 * The price record carries a `storeId`: DekaMarkt prices per store, and the
 * payload holds whichever store an anonymous visitor gets. That is the price
 * the site itself shows for such a visitor, so it is the one to track.
 */
final readonly class DekaMarktAdapter implements HostSpecificAdapter, ShopAdapter
{
    private const string IMAGE_BASE = 'https://web-fileserver.dekamarkt.nl/';

    public function key(): string
    {
        return 'dekamarkt';
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        if (! HostUrl::matches($url, 'dekamarkt.nl')) {
            return ExtractionResult::skip();
        }

        $data = NuxtData::decode($html);

        if ($data === null) {
            return ExtractionResult::failed('dekamarkt_no_payload');
        }

        $productId = HostUrl::lastNumericSegment($url);

        if ($productId === null) {
            return ExtractionResult::failed('dekamarkt_no_product_id');
        }

        $product = NuxtData::recordsFor($data, ['productId', 'headerText', 'packaging'], 'productId', $productId)[0] ?? null;
        $priceRecords = NuxtData::recordsFor($data, ['productId', 'normalPrice'], 'productId', $productId);

        if ($product === null || $priceRecords === []) {
            return ExtractionResult::failed('dekamarkt_no_product');
        }

        $prices = [];

        foreach ($priceRecords as $record) {
            $candidate = PriceNormalizer::fromMixed(self::currentPrice($data, $record));

            if ($candidate !== null) {
                $prices[$candidate] = true;
            }
        }

        // A payload that states two different prices for one article gives no
        // basis to pick one; the product page shows a single price, so this
        // means the shape changed under us.
        if (count($prices) > 1) {
            return ExtractionResult::failed('dekamarkt_ambiguous_price');
        }

        $price = array_key_first($prices);

        if ($price === null) {
            return ExtractionResult::failed('dekamarkt_no_price');
        }

        $title = NuxtData::value($data, $product, 'headerText');
        $packaging = NuxtData::value($data, $product, 'packaging');

        return ExtractionResult::success(new ShopSnapshot(
            title: is_string($title) && $title !== '' ? $title : 'Unknown',
            imageUrl: self::imageUrl($data, $product),
            price: $price,
            currency: 'EUR',
            // The payload carries no availability flag; a delisted product
            // answers with the "artikel niet gevonden" page instead.
            inStock: true,
            raw: ['source' => 'dekamarkt'],
            packSize: is_string($packaging) && $packaging !== '' ? $packaging : null,
            packSizeAuthoritative: true,
        ));
    }

    /**
     * The offer price while its window is open, the shelf price otherwise —
     * the payload keeps last week's offer around after it expires.
     *
     * @param  list<mixed>  $data
     * @param  array<string, mixed>  $record
     */
    private static function currentPrice(array $data, array $record): mixed
    {
        $normal = NuxtData::value($data, $record, 'normalPrice');
        $offer = NuxtData::value($data, $record, 'offerPrice');

        if ($offer === null || ! self::offerIsRunning($data, $record)) {
            return $normal;
        }

        return $offer;
    }

    /**
     * @param  list<mixed>  $data
     * @param  array<string, mixed>  $record
     */
    private static function offerIsRunning(array $data, array $record): bool
    {
        $start = self::date($data, $record, 'startDate');
        $end = self::date($data, $record, 'endDate');

        // An undated or unparseable window is not evidence of a live offer.
        // The payload keeps last week's offer price around, so trusting it
        // would resurrect an expired discount as a price drop.
        if ($start === null || $end === null) {
            return false;
        }

        return CarbonImmutable::now()->between($start, $end);
    }

    /**
     * @param  list<mixed>  $data
     * @param  array<string, mixed>  $record
     */
    private static function date(array $data, array $record, string $key): ?CarbonImmutable
    {
        $value = NuxtData::value($data, $record, $key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<mixed>  $data
     * @param  array<string, mixed>  $product
     */
    private static function imageUrl(array $data, array $product): ?string
    {
        $images = NuxtData::value($data, $product, 'images');

        if (! is_array($images)) {
            return null;
        }

        foreach ($images as $index) {
            $image = is_int($index) && array_key_exists($index, $data) ? $data[$index] : null;

            if (! is_array($image)) {
                continue;
            }

            /** @var array<string, mixed> $image */
            $path = NuxtData::value($data, $image, 'image');

            if (is_string($path) && $path !== '') {
                return self::IMAGE_BASE . ltrim($path, '/');
            }
        }

        return null;
    }
}
