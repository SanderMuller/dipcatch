<?php declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The product name a shop page gives, with the shop's own junk taken off.
 *
 * A stored title is read by a person scanning their product list, so it wants
 * to say what the product is and nothing else. Shop pages write titles for
 * search engines instead, and three kinds of tail come back every time:
 * Shopify's `- Default Title` placeholder, the shop's own name after a
 * separator, and a Dutch buying word ("kopen", "bestellen").
 *
 * Cleaning happens on the draft, so every client gets it — the web preview,
 * an MCP tool, and anything written against them later. Only trailing junk is
 * removed: a separator inside the name ("Coca-Cola", "Bar - 12 x 55 g") says
 * something about the product and stays.
 */
final readonly class ProductTitle
{
    /** The Shopify placeholder for a product with one unnamed variant. */
    private const string DEFAULT_VARIANT = 'default title';

    /** Dutch buying words a shop appends for search engines. */
    private const array SEO_TAILS = ['kopen', 'bestellen', 'goedkoop'];

    /**
     * Only ever removed in front of one of those: on its own it ends real
     * product names ("Nintendo Switch Online").
     */
    private const string QUALIFIER = 'online';

    /**
     * @param  string|null  $host  The shop the title was read from. Without it
     *                             the shop-name rule cannot run, and the rest
     *                             still does.
     */
    public static function clean(?string $title, ?string $host = null): ?string
    {
        if ($title === null) {
            return null;
        }

        $cleaned = trim($title);

        // Each pass can expose another tail: "… | Body & Shape Store" hides
        // the "kopen" in front of it. Repeat until nothing more comes off.
        do {
            $before = $cleaned;
            $cleaned = self::stripSeparatedTail($cleaned, $host);
            $cleaned = self::stripSeoTail($cleaned);
        } while ($cleaned !== $before && $cleaned !== '');

        // A title that was nothing but junk keeps what the page said: a blank
        // name helps nobody, and the caller can still override it.
        return $cleaned === '' ? trim($title) : $cleaned;
    }

    /**
     * Drops a final `|`, `–` or `-` segment that names the shop, or that is
     * Shopify's placeholder variant.
     */
    private static function stripSeparatedTail(string $title, ?string $host): string
    {
        $position = self::lastSeparator($title);

        if ($position === null) {
            return $title;
        }

        $tail = trim(mb_substr($title, $position + 1));
        $head = trim(mb_substr($title, 0, $position));

        if ($head === '' || $tail === '') {
            return $title;
        }

        if (mb_strtolower($tail) === self::DEFAULT_VARIANT || self::namesTheShop($tail, $host)) {
            return $head;
        }

        return $title;
    }

    /** The offset of the last separator, or null when the title has none. */
    private static function lastSeparator(string $title): ?int
    {
        $positions = [];

        foreach (['|', '–', '—', '-'] as $separator) {
            $position = mb_strrpos($title, $separator);

            if ($position !== false) {
                $positions[] = $position;
            }
        }

        return $positions === [] ? null : max($positions);
    }

    /**
     * True when the segment is the shop's name.
     *
     * Matched whole against a label of the host, never as a fragment of it:
     * a title ending in "- Bio" on `biomarkt.nl` is saying something about
     * the product, and a substring test would eat it.
     */
    private static function namesTheShop(string $tail, ?string $host): bool
    {
        if ($host === null || $host === '') {
            return false;
        }

        // A page writes "Body & Shape Store" for `bodyandshapestore.nl`, and
        // "B&B" for `bb.nl`, so the ampersand is tried both ways.
        $tailKeys = array_filter([
            self::alphanumeric(str_replace('&', 'and', $tail)),
            self::alphanumeric(str_replace('&', '', $tail)),
        ]);

        return array_any(
            $tailKeys,
            static fn (string $key): bool => in_array($key, self::hostNames($host), strict: true),
        );
    }

    /**
     * The names a host can be known by: every label except the suffix, so
     * `www.shop.example.com` offers `shop` and `example`.
     *
     * Whole labels only. Matching a piece of one would eat the tail of
     * "Melk - Bio" on `bio-markt.nl`, which is the product, not the shop.
     *
     * @return list<string>
     */
    private static function hostNames(string $host): array
    {
        $labels = explode('.', mb_strtolower($host));

        // The suffix is never the shop's name. A two-part suffix such as
        // `co.uk` leaves one extra label in, which only widens the match to
        // "co" — no real title ends in that.
        array_pop($labels);

        $names = [];

        foreach ($labels as $label) {
            if ($label !== 'www' && $label !== '') {
                $names[] = self::alphanumeric($label);
            }
        }

        return array_values(array_filter($names));
    }

    /** Drops a trailing Dutch buying word, and the comma or dash before it. */
    private static function stripSeoTail(string $title): string
    {
        $words = preg_split('/\\s+/', $title) ?: [];
        $last = end($words);

        if (! is_string($last) || ! in_array(self::bareWord($last), self::SEO_TAILS, strict: true)) {
            return $title;
        }

        array_pop($words);

        // "online bestellen" loses both words; "Nintendo Switch Online" is
        // never reached, because nothing was stripped in front of it.
        if (self::bareWord((string) end($words)) === self::QUALIFIER) {
            array_pop($words);
        }

        return (string) preg_replace('/[\\s,\\-–|]+$/u', '', implode(' ', $words));
    }

    /** One word, lowercased, without the punctuation stuck to it. */
    private static function bareWord(string $word): string
    {
        return mb_strtolower((string) preg_replace('/^[\\s,.!\\-–|]+|[\\s,.!\\-–|]+$/u', '', $word));
    }

    /** Letters and digits only, so separators and spacing cannot matter. */
    private static function alphanumeric(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(Str::ascii($value)));
    }
}
