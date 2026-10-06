<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\PriceNormalizer;
use App\Support\NextData;

/**
 * The variants a zooplus or bitiba page lists in its Next.js page state, and
 * the one a URL or a chosen variant key names.
 *
 * Each variant carries one offer: its regular price as `currentPrice`, the
 * repeat-order and zooclub prices under `discounts`, and the stated size.
 */
final readonly class ZooplusPageState
{
    /**
     * @param  list<array<mixed>>  $variants
     */
    private function __construct(
        public array $variants,
        public ?string $pageVariant,
    ) {}

    public static function read(string $html): ?self
    {
        $state = NextData::decode($html);
        $page = $state === null ? null : NextData::value($state, 'props.pageProps.pageLevelProps');
        $variants = is_array($page) ? NextData::value($page, 'productDetails.product.articleVariants') : null;

        if (! is_array($page) || ! is_array($variants)) {
            return null;
        }

        return new self(
            array_values(array_filter($variants, is_array(...))),
            is_string($page['activeVariantFromUrl'] ?? null) ? $page['activeVariantFromUrl'] : null,
        );
    }

    /**
     * The first hint that names a variant, in the JSON-LD's own order: a
     * chosen variant outranks the page's own and the URL's.
     *
     * @param  list<?string>  $hints
     * @return array<mixed>|null
     */
    public function named(array $hints): ?array
    {
        foreach ($hints as $hint) {
            $match = $hint === null ? null : array_find($this->variants, static fn (array $row): bool => self::names($row, $hint));

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * The price anyone pays for one delivery of a variant: the regular price,
     * not a repeat-order or member discount.
     *
     * @param  array<mixed>  $variant
     */
    public static function regularPrice(array $variant): ?string
    {
        $offer = $variant['offers'][0] ?? null;
        $value = is_array($offer) ? ($offer['price']['currentPrice']['value'] ?? null) : null;

        return (is_int($value) || is_float($value)) && $value > 0 ? PriceNormalizer::fromMixed($value) : null;
    }

    public static function queryVariant(string $url): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $variant = $query['activeVariant'] ?? $query['variantId'] ?? null;

        return is_string($variant) ? $variant : null;
    }

    /**
     * Whether a hint names this variant: its gtin, a sku or URL ending in
     * ".{variantId}", or the bare variant id.
     *
     * @param  array<mixed>  $variant
     */
    private static function names(array $variant, string $hint): bool
    {
        $id = $variant['variantId'] ?? null;

        if (is_string($variant['ean'] ?? null) && $variant['ean'] === $hint) {
            return true;
        }

        if (! is_int($id)) {
            return false;
        }

        $value = str_contains($hint, '?') ? (self::queryVariant($hint) ?? '') : $hint;

        return $value === (string) $id || str_ends_with($value, '.' . $id);
    }
}
