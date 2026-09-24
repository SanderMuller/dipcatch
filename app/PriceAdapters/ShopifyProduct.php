<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * The variant list Shopify writes into every product page, whatever the theme:
 * `var meta = {"product":{"variants":[…]}}`, the ShopifyAnalytics payload.
 *
 * Each variant carries its id, its price in minor units, its name and SKU. It
 * carries no stock, so availability is read from a theme's own variant JSON
 * when the page has one, and stays unknown when it does not.
 */
final readonly class ShopifyProduct
{
    private const string MARKER = 'var meta = ';

    /**
     * @param  list<array{id: string, price: int, name: string, title: string, sku: ?string}>  $variants
     * @param  array<string, bool>  $available  variant id => available, from theme JSON
     * @param  int  $listed  every row the page lists, read or not
     */
    private function __construct(
        public array $variants,
        public ?string $currency,
        public array $available,
        public int $listed,
    ) {}

    /** Null when the page is not a Shopify product page, or its list cannot be read. */
    public static function from(string $html): ?self
    {
        $meta = self::metaObject($html);
        $product = is_array($meta['product'] ?? null) ? $meta['product'] : null;
        $rows = is_array($product['variants'] ?? null) ? $product['variants'] : [];
        $variants = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_scalar($row['id'] ?? null) || ! is_int($row['price'] ?? null)) {
                continue;
            }

            $name = is_string($row['name'] ?? null) ? $row['name'] : '';
            $title = is_string($row['public_title'] ?? null) && $row['public_title'] !== '' ? $row['public_title'] : $name;

            $variants[] = [
                'id' => (string) $row['id'],
                'price' => $row['price'],
                'name' => $name,
                'title' => $title,
                'sku' => is_string($row['sku'] ?? null) && $row['sku'] !== '' ? $row['sku'] : null,
            ];
        }

        if ($rows === []) {
            return null;
        }

        return new self($variants, self::currency($html), self::availability($html), count($rows));
    }

    /** How many variants a page lists, or null on a page that is not Shopify's. */
    public static function variantCount(string $html): ?int
    {
        return self::from($html)?->count();
    }

    public function count(): int
    {
        return $this->listed;
    }

    /**
     * Whether every listed row could be read. A row left out would make a
     * two-variant page read as one, so a reader must not price a partial list.
     */
    public function isComplete(): bool
    {
        return count($this->variants) === $this->listed;
    }

    /**
     * The variant a key names: a variant URL, a variant id or a SKU.
     *
     * @return array{id: string, price: int, name: string, title: string, sku: ?string}|null
     */
    public function variantFor(string $key): ?array
    {
        $id = self::variantIdIn($key) ?? $key;

        return array_find($this->variants, static fn (array $variant): bool => $variant['id'] === $id)
            ?? array_find($this->variants, static fn (array $variant): bool => $variant['sku'] === $key);
    }

    /**
     * What Shopify shows when no variant is named: the first one in stock, or
     * the first one when the page says none is.
     *
     * @return array{id: string, price: int, name: string, title: string, sku: ?string}
     */
    public function defaultVariant(): array
    {
        return array_find($this->variants, fn (array $variant): bool => ($this->available[$variant['id']] ?? true) === true)
            ?? $this->variants[0];
    }

    /**
     * What a URL's `?variant=` names, or null when it has none. A value that
     * is no variant id is still returned: the link named something, and it
     * must match nothing rather than read as naming nothing.
     */
    public static function variantIdIn(string $url): ?string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query)) {
            return null;
        }

        parse_str($query, $params);

        if (! array_key_exists('variant', $params)) {
            return null;
        }

        // Empty or an array: still named, so it matches nothing.
        return is_string($params['variant']) ? $params['variant'] : '';
    }

    /**
     * Whether a key is about this page: a variant URL must name the same
     * product handle, or a key from another product would price this one
     * while its link opens the other. Ids and SKUs are this page's by nature.
     */
    public static function keyIsForPage(string $key, string $url): bool
    {
        if (! str_starts_with($key, 'http')) {
            return true;
        }

        return self::handle($key) !== null && self::handle($key) === self::handle($url);
    }

    private static function handle(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && preg_match('#/products/([^/]+)#', $path, $match) === 1 ? $match[1] : null;
    }

    /**
     * The page URL with this variant named, which is how Shopify links one.
     * Other parameters stay: one may select the currency the price was read in.
     */
    public static function variantUrl(string $url, string $id): string
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $params);
        $params['variant'] = $id;
        $base = strtok($url, '?#');

        return (is_string($base) ? $base : $url) . '?' . http_build_query($params);
    }

    /**
     * Minor units as a decimal string: 1299 is "12.99".
     */
    public static function decimal(int $minor): string
    {
        return bcdiv((string) $minor, '100', 2);
    }

    /**
     * The object after `var meta = `.
     *
     * @return array<mixed>|null
     */
    private static function metaObject(string $html): ?array
    {
        $start = strpos($html, self::MARKER);

        return $start === false ? null : EmbeddedJson::objectAt($html, $start + strlen(self::MARKER));
    }

    private static function currency(string $html): ?string
    {
        if (preg_match('/Shopify\.currency\s*=\s*\{[^}]*"active"\s*:\s*"([A-Za-z]{3})"/', $html, $match) === 1) {
            return strtoupper($match[1]);
        }

        return null;
    }

    /**
     * Stock per variant from any theme JSON that lists the variants with an
     * `available` flag: a bare list, or an object holding `variants`.
     *
     * @return array<string, bool>
     */
    private static function availability(string $html): array
    {
        $available = [];

        preg_match_all('#<script[^>]*type="application/json"[^>]*>(.*?)</script>#s', $html, $scripts);

        foreach ($scripts[1] as $script) {
            $decoded = EmbeddedJson::decode(trim($script));

            if ($decoded === null) {
                continue;
            }

            $rows = array_is_list($decoded) ? $decoded : ($decoded['variants'] ?? null);

            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (is_array($row) && is_scalar($row['id'] ?? null) && is_bool($row['available'] ?? null)) {
                    $available[(string) $row['id']] = $row['available'];
                }
            }
        }

        return $available;
    }
}
