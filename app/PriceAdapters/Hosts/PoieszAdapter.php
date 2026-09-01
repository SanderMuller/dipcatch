<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\PriceNormalizer;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use App\Support\Gtin;
use App\Support\NuxtData;
use App\Support\UrlNormalizer;

/**
 * Host-specific adapter for the Poiesz webshop. The page carries no JSON-LD,
 * microdata or price meta tags — everything lives in the Nuxt
 * `__NUXT_DATA__` payload, a flat array where object values are indices into
 * that same array. The product record holds `price`, `name`, `image`,
 * `packageDescription` and `ean` (observed 2026-09-01).
 *
 * The payload also carries recommended products, so the record is matched on
 * the id in the URL.
 */
final readonly class PoieszAdapter implements HostSpecificAdapter, ShopAdapter
{
    public function key(): string
    {
        return 'poiesz';
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        if (! self::handles($url)) {
            return ExtractionResult::skip();
        }

        $data = NuxtData::decode($html);

        if ($data === null) {
            return ExtractionResult::failed('poiesz_no_payload');
        }

        $record = self::productRecord($data, self::productIdFromUrl($url));

        if ($record === null) {
            return ExtractionResult::failed('poiesz_no_product');
        }

        $price = PriceNormalizer::fromMixed(self::value($data, $record, 'price'));

        if ($price === null) {
            return ExtractionResult::failed('poiesz_no_price');
        }

        $title = self::value($data, $record, 'name');
        $image = self::value($data, $record, 'image');
        $packageDescription = self::value($data, $record, 'packageDescription');

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
            gtin: Gtin::normalize(self::value($data, $record, 'ean')),
            gtinAuthoritative: true,
        ));
    }

    public static function handles(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $host = is_string($host) ? UrlNormalizer::normalizeHost($host) : '';

        return $host === 'poiesz-supermarkten.nl' || str_ends_with($host, '.poiesz-supermarkten.nl');
    }

    /**
     * The product record carries `price` alongside `name` and `id`. When the
     * URL names an id, only that record counts — recommended products sit in
     * the same payload with the same shape.
     *
     * @param  list<mixed>  $data
     * @return array<string, mixed>|null
     */
    private static function productRecord(array $data, ?string $productId): ?array
    {
        $fallback = null;

        foreach ($data as $element) {
            if (! is_array($element) || ! isset($element['price'], $element['name'], $element['id'])) {
                continue;
            }

            /** @var array<string, mixed> $element */
            $recordId = self::value($data, $element, 'id');

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
