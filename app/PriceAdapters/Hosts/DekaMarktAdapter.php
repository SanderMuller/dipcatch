<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use App\Support\NuxtData;
use App\Support\UrlNormalizer;
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
        if (! self::handles($url)) {
            return ExtractionResult::skip();
        }

        $data = NuxtData::decode($html);

        if ($data === null) {
            return ExtractionResult::failed('dekamarkt_no_payload');
        }

        $productId = self::productIdFromUrl($url);
        $product = self::recordWith($data, ['productId', 'headerText', 'packaging'], $productId);
        $priceRecord = self::recordWith($data, ['productId', 'normalPrice'], $productId);

        if ($product === null || $priceRecord === null) {
            return ExtractionResult::failed('dekamarkt_no_product');
        }

        $price = PriceNormalizer::fromMixed(self::currentPrice($data, $priceRecord));

        if ($price === null) {
            return ExtractionResult::failed('dekamarkt_no_price');
        }

        $title = self::value($data, $product, 'headerText');
        $packaging = self::value($data, $product, 'packaging');

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

    public static function handles(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $host = is_string($host) ? UrlNormalizer::normalizeHost($host) : '';

        return $host === 'dekamarkt.nl' || str_ends_with($host, '.dekamarkt.nl');
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
        $normal = self::value($data, $record, 'normalPrice');
        $offer = self::value($data, $record, 'offerPrice');

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

        if ($start === null || $end === null) {
            // No window means the payload states an offer price without
            // dating it; trust it rather than dropping a live discount.
            return true;
        }

        return CarbonImmutable::now()->between($start, $end);
    }

    /**
     * @param  list<mixed>  $data
     * @param  array<string, mixed>  $record
     */
    private static function date(array $data, array $record, string $key): ?CarbonImmutable
    {
        $value = self::value($data, $record, $key);

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
        $images = self::value($data, $product, 'images');

        if (! is_array($images)) {
            return null;
        }

        foreach ($images as $index) {
            $image = is_int($index) && array_key_exists($index, $data) ? $data[$index] : null;

            if (! is_array($image)) {
                continue;
            }

            /** @var array<string, mixed> $image */
            $path = self::value($data, $image, 'image');

            if (is_string($path) && $path !== '') {
                return self::IMAGE_BASE . ltrim($path, '/');
            }
        }

        return null;
    }

    /**
     * The first record carrying every named key, preferring the one whose
     * `productId` matches the URL — a page also carries related products.
     *
     * @param  list<mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, mixed>|null
     */
    private static function recordWith(array $data, array $keys, ?string $productId): ?array
    {
        $fallback = null;

        foreach ($data as $element) {
            if (! is_array($element)) {
                continue;
            }

            foreach ($keys as $key) {
                if (! isset($element[$key])) {
                    continue 2;
                }
            }

            /** @var array<string, mixed> $element */
            $recordId = self::value($data, $element, 'productId');

            if ($productId !== null && (is_string($recordId) || is_int($recordId)) && (string) $recordId === $productId) {
                return $element;
            }

            $fallback ??= $element;
        }

        return $productId === null ? $fallback : null;
    }

    /**
     * @param  list<mixed>  $data
     * @param  array<string, mixed>  $record
     */
    private static function value(array $data, array $record, string $key): mixed
    {
        $index = $record[$key] ?? null;

        return is_int($index) && array_key_exists($index, $data) ? $data[$index] : null;
    }

    private static function productIdFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
        $last = end($segments);

        return is_string($last) && ctype_digit($last) ? $last : null;
    }
}
