<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\JsonLdAdapter;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use App\Support\NuxtData;
use App\Support\UrlNormalizer;

/**
 * Host-specific adapter for lidl.nl. Price, title, and image come from the
 * page's JSON-LD. The pack size lives only in the Nuxt `__NUXT_DATA__`
 * payload: the product record's `price` points at a price record whose
 * `packaging.text` holds the size ("370 g"), so this adapter augments the
 * JSON-LD snapshot with it.
 */
final readonly class LidlAdapter implements HostSpecificAdapter, ShopAdapter
{
    public function key(): string
    {
        return 'lidl';
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        $host = self::hostFor($url);
        if ($host !== 'lidl.nl' && ! str_ends_with((string) $host, '.lidl.nl')) {
            return ExtractionResult::skip();
        }

        $result = new JsonLdAdapter()->extract($url, $html, $context);
        if (! $result->isSuccess()) {
            return $result->isSkip() ? ExtractionResult::failed('lidl_extraction_failed') : $result;
        }

        $snapshot = $result->snapshot;
        assert($snapshot instanceof ShopSnapshot);

        $packaging = self::packagingFromNuxtPayload($html, self::productIdFromUrl($url));
        if ($packaging === null) {
            return $result;
        }

        return ExtractionResult::success(new ShopSnapshot(
            title: $snapshot->title,
            imageUrl: $snapshot->imageUrl,
            price: $snapshot->price,
            currency: $snapshot->currency,
            inStock: $snapshot->inStock,
            raw: $snapshot->raw,
            packSize: $packaging,
            packSizeAuthoritative: true,
        ));
    }

    /**
     * The product record is the dict carrying both `productId` and `price`;
     * `productId` disambiguates when related products ride along. The chain
     * is product → price record → packaging record → `text`.
     */
    private static function packagingFromNuxtPayload(string $html, ?string $productId): ?string
    {
        $data = NuxtData::decode($html);
        if ($data === null) {
            return null;
        }

        $deref = static fn (mixed $v): mixed => is_int($v) && isset($data[$v]) ? $data[$v] : null;

        $fallback = null;

        foreach ($data as $element) {
            if (! is_array($element) || ! isset($element['productId'], $element['price'])) {
                continue;
            }

            $price = $deref($element['price']);
            if (! is_array($price)) {
                continue;
            }

            $packagingRecord = $deref($price['packaging'] ?? null);
            $packaging = is_array($packagingRecord) ? $deref($packagingRecord['text'] ?? null) : null;
            if (! is_string($packaging) || $packaging === '') {
                continue;
            }

            $recordId = $deref($element['productId']);
            if ($productId !== null && (is_string($recordId) || is_int($recordId)) && (string) $recordId === $productId) {
                return $packaging;
            }

            $fallback ??= $packaging;
        }

        return $fallback;
    }

    /**
     * Lidl product URLs end in a `p<digits>` segment (`/p/lay-s/p10033095`).
     */
    private static function productIdFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path)) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
        $last = end($segments);

        if (! is_string($last) || preg_match('/^p(\d+)$/', $last, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    private static function hostFor(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? UrlNormalizer::normalizeHost($host) : null;
    }
}
