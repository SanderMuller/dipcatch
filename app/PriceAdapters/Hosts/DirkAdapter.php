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
 * Host-specific adapter for dirk.nl. Price and title come from the page's
 * JSON-LD (which carries the live promo price under a capitalized `Price`
 * key the JsonLdAdapter already accepts). The pack size lives only in the
 * Nuxt `__NUXT_DATA__` payload — a flat array where object values are
 * indices into the same array — as the product record's `packaging` field
 * ("150 g"), so this adapter augments the JSON-LD snapshot with it.
 */
final readonly class DirkAdapter implements HostSpecificAdapter, ShopAdapter
{
    public function key(): string
    {
        return 'dirk';
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        $host = self::hostFor($url);
        if ($host !== 'dirk.nl' && ! str_ends_with((string) $host, '.dirk.nl')) {
            return ExtractionResult::skip();
        }

        $result = new JsonLdAdapter()->extract($url, $html, $context);
        if (! $result->isSuccess()) {
            return $result->isSkip() ? ExtractionResult::failed('dirk_extraction_failed') : $result;
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
     * The product record is the dict carrying both `headerText` and
     * `packaging`; `productId` disambiguates when related products ride
     * along.
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
            if (! is_array($element) || ! isset($element['packaging'], $element['headerText'])) {
                continue;
            }

            $packaging = $deref($element['packaging']);
            if (! is_string($packaging) || $packaging === '') {
                continue;
            }

            $recordId = $deref($element['productId'] ?? null);
            if ($productId !== null && (is_string($recordId) || is_int($recordId)) && (string) $recordId === $productId) {
                return $packaging;
            }

            $fallback ??= $packaging;
        }

        return $fallback;
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

    private static function hostFor(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? UrlNormalizer::normalizeHost($host) : null;
    }
}
