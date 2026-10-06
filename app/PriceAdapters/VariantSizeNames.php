<?php declare(strict_types=1);

namespace App\PriceAdapters;

use App\Support\PackSize;

/**
 * The names a page gives its variants outside the JSON-LD they are read from.
 *
 * Medpets and Shopify shops name each JSON-LD offer after the product alone
 * ("Cavalor Muscle Motion"), and state the size only in their analytics data:
 * Shopify's `ShopifyAnalytics.meta` and Medpets' dataLayer. THG shops
 * (Myprotein, Lookfantastic) name a variant after its flavour and state the
 * amount only in the page state's choices. Without the size
 * in the name, a variant cannot be matched to the pack a product tracks, and a
 * shop added for one variant is stored with no pack size.
 */
final class VariantSizeNames
{
    /**
     * The variants, each one whose title states no size renamed after the
     * page's own name for it, when that name does state one.
     *
     * @param  list<VariantCandidate>  $variants
     * @return list<VariantCandidate>
     */
    public static function fill(array $variants, string $html): array
    {
        $names = $variants === [] ? [] : self::names($html);

        if ($names === []) {
            return $variants;
        }

        return array_map(static function (VariantCandidate $variant) use ($names): VariantCandidate {
            $name = self::statesSize($variant->title) ? null : self::lookUp($names, $variant->key);

            return $name === null
                ? $variant
                : new VariantCandidate($variant->key, $name, $variant->price, $variant->currency, $variant->inStock);
        }, $variants);
    }

    /**
     * The page's name for one variant, when it states a size. `$key` is a
     * variant key or a URL whose `sku` or `variant` parameter names one.
     */
    public static function nameFor(string $key, string $html): ?string
    {
        return self::lookUp(self::names($html), $key);
    }

    public static function statesSize(string $name): bool
    {
        return PackSize::resolve(packSize: null, authoritative: false, title: $name) instanceof PackSize;
    }

    /**
     * @param  array<int|string, string>  $names
     */
    private static function lookUp(array $names, string $key): ?string
    {
        foreach (self::identifiers($key) as $identifier) {
            $name = $names[$identifier] ?? null;

            if ($name !== null && self::statesSize($name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The key itself, plus the `sku` and `variant` query parameters when the
     * key is a URL: Medpets keys its offers by `?sku=`, Shopify selects a
     * variant by `?variant=`.
     *
     * @return list<string>
     */
    private static function identifiers(string $key): array
    {
        $identifiers = [$key];
        $query = parse_url($key, PHP_URL_QUERY);

        if (is_string($query)) {
            parse_str($query, $parameters);

            foreach (['sku', 'variant'] as $parameter) {
                if (is_string($parameters[$parameter] ?? null) && $parameters[$parameter] !== '') {
                    $identifiers[] = $parameters[$parameter];
                }
            }
        }

        return $identifiers;
    }

    /**
     * Every variant name the page states, keyed by SKU and by variant id. An
     * all-digit SKU is an integer key, as PHP stores one.
     *
     * @return array<int|string, string>
     */
    private static function names(string $html): array
    {
        return self::shopifyNames($html) + self::dataLayerNames($html) + ThgVariantNames::names($html);
    }

    /**
     * @return array<string, string>
     */
    private static function shopifyNames(string $html): array
    {
        if (preg_match('/var meta = (\{"product".*?\});\s*\n/s', $html, $match) !== 1) {
            return [];
        }

        $meta = json_decode($match[1], true);
        $variants = is_array($meta) && is_array($meta['product']['variants'] ?? null) ? $meta['product']['variants'] : [];
        $names = [];

        foreach ($variants as $variant) {
            $name = is_array($variant) ? ($variant['name'] ?? null) : null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            foreach (['sku', 'id'] as $field) {
                $identifier = $variant[$field] ?? null;

                if ((is_string($identifier) || is_int($identifier)) && (string) $identifier !== '') {
                    $names[(string) $identifier] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * Medpets pushes one dataLayer product per SKU: `"variant":"MP28082",
     * "dimension2":"Hill's … - 1,5 kg"`.
     *
     * @return array<string, string>
     */
    private static function dataLayerNames(string $html): array
    {
        preg_match_all('/"variant":"((?:[^"\\\\]|\\\\.)*)","dimension2":"((?:[^"\\\\]|\\\\.)*)"/', $html, $matches, PREG_SET_ORDER);
        $names = [];

        foreach ($matches as $match) {
            $identifier = json_decode('"' . $match[1] . '"');
            $name = json_decode('"' . $match[2] . '"');

            if (is_string($identifier) && is_string($name) && $identifier !== '' && $name !== '') {
                $names[$identifier] ??= $name;
            }
        }

        return $names;
    }
}
