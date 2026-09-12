<?php declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Config;

/**
 * One landing page per supported shop.
 *
 * The shops come from `site.supported_hosts`, so a shop dropped from the
 * config loses its page, its sitemap entry and its links in the same edit.
 *
 * What each page claims about a shop is read off the adapter that serves it,
 * not written from memory. `PROMOTION_AWARE` lists the hosts whose adapter
 * parses a promotion window (`App\PriceAdapters\PromotionWindow`), and
 * `ARTICLE_NUMBER` the hosts whose adapter reads a GTIN. A shop in neither
 * list gets the plain description, which is the honest one.
 */
final class ShopPages
{
    /**
     * Hosts whose adapter reads the offer price and the date it ends.
     *
     * ah.nl through `AhApiSource` (Bonus, authoritative window); the rest
     * through their own adapter under `App\PriceAdapters\Hosts`.
     */
    private const array PROMOTION_AWARE = ['ah.nl', 'aldi.nl', 'dekamarkt.nl', 'dirk.nl', 'jumbo.com', 'lidl.nl'];

    /** Hosts whose adapters convert public multi-buy terms to an effective item price. */
    private const array BUNDLE_AWARE = ['ah.nl', 'jumbo.com'];

    /** Hosts whose adapter reads the article number, which catches a mismatched pack. */
    private const array ARTICLE_NUMBER = ['dierapotheker.nl', 'poiesz.nl', 'vomar.nl'];

    /**
     * @return list<ShopPage>
     */
    public static function all(): array
    {
        $pages = [];

        foreach (SupportedShops::rows() as $row) {
            $pages[] = self::page($row['host'], $row['name']);
        }

        return $pages;
    }

    public static function find(string $slug): ?ShopPage
    {
        foreach (self::all() as $page) {
            if ($page->slug === $slug) {
                return $page;
            }
        }

        return null;
    }

    /**
     * A host makes a URL-safe slug by swapping its dots: `ah.nl` becomes
     * `ah-nl`. Reversible, and it keeps the shop recognisable in the URL.
     */
    public static function slug(string $host): string
    {
        return str_replace('.', '-', $host);
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_map(static fn (ShopPage $page): string => $page->slug, self::all());
    }

    public static function slugPattern(): string
    {
        $slugs = self::slugs();

        if ($slugs === []) {
            // An empty alternation matches the empty string, which would put
            // every unmatched URL through this route.
            return '(?!)';
        }

        return implode('|', array_map(static fn (string $slug): string => preg_quote($slug, '/'), $slugs));
    }

    private static function page(string $host, string $name): ShopPage
    {
        return new ShopPage(
            host: $host,
            name: $name,
            slug: self::slug($host),
            heading: __('Price alerts for :shop', ['shop' => $name]),
            description: __('Track what you buy at :shop and hear about it when the price drops. DipCatch re-checks the page for you and compares :shop against the other shops that sell the same thing.', ['shop' => $name]),
            intro: __('DipCatch watches the products you already buy at :shop, compares them against the other shops that sell the same thing, and tells you when one drops past your threshold.', ['shop' => $name]),
            facts: self::facts($host, $name),
            faq: self::faq($host, $name),
            useCases: self::useCasesFor($host),
        );
    }

    /**
     * @return list<string>
     */
    private static function facts(string $host, string $name): array
    {
        $hours = Config::get('dipcatch.recheck.interval_hours', 24);

        $facts = [
            __('DipCatch has a reader written for :shop, so the price comes off the product page itself even when a generic read would get it wrong.', ['shop' => $name]),
            __('Every tracked page is re-checked about every :hours hours without you opening anything, and four times as often on Pro.', ['hours' => is_numeric($hours) ? (int) $hours : 24]),
        ];

        if (in_array($host, self::BUNDLE_AWARE, strict: true)) {
            $facts[] = __('A supported multi-buy offer at :shop is read with the date it ends. DipCatch converts it into an effective item price and always shows the required quantity.', ['shop' => $name]);
        } elseif (in_array($host, self::PROMOTION_AWARE, strict: true)) {
            $facts[] = __('An offer price at :shop is read with the date it ends, so a temporary price is never mistaken for the new normal.', ['shop' => $name]);
        }

        if (in_array($host, self::ARTICLE_NUMBER, strict: true)) {
            $facts[] = __('DipCatch reads the article number at :shop and warns you when two shops turn out to be selling different packs.', ['shop' => $name]);
        }

        $facts[] = __('Where the pack size is on the page, DipCatch compares per kilo, litre or piece, so a 200 g bag and a 370 g bag can be judged against each other.');

        return $facts;
    }

    /**
     * @return list<array{q: string, a: string}>
     */
    private static function faq(string $host, string $name): array
    {
        $offerAnswer = match (true) {
            in_array($host, self::BUNDLE_AWARE, strict: true) => __('Yes. DipCatch tracks supported multi-buy offers as an effective item price and shows how many items the offer requires.'),
            in_array($host, self::PROMOTION_AWARE, strict: true) => __('Yes. The offer price is read along with the date it runs until, and the product page shows both, so you can see whether a price is a deal or the new level.', []),
            default => __('DipCatch reads the price :shop shows on the product page. When that price is an offer, that is the price you get alerted on.', ['shop' => $name]),
        };

        return [
            [
                'q' => __('Can DipCatch track :shop?', ['shop' => $name]),
                'a' => __('Yes. Paste the link to a :shop product page and DipCatch reads the title, the image, the price and the pack size, then shows you what it found before anything is saved.', ['shop' => $name]),
            ],
            [
                'q' => __('Does it see the offer price at :shop?', ['shop' => $name]),
                'a' => $offerAnswer,
            ],
            [
                'q' => __('Do I need an extension, or an account at :shop?', ['shop' => $name]),
                'a' => __('Neither. You paste a link in your browser and DipCatch does the checking. Alerts arrive as a daily email digest, under the bell in the app, or as a browser push if you turn that on.'),
            ],
            [
                'q' => __('Can I compare :shop against another shop?', ['shop' => $name]),
                'a' => __('That is the point of it. Add the same product at a second shop and DipCatch shows which one is cheapest right now, comparing per kilo, litre or piece where it can read the pack size.'),
            ],
            [
                'q' => __('Does DipCatch only work at :shop?', ['shop' => $name]),
                'a' => __('No. Paste a product link from almost any webshop. The reader for :shop is for when a generic read is not enough.', ['shop' => $name]),
            ],
        ];
    }

    /**
     * The category pages that name this shop, taken from the same config the
     * pages themselves read.
     *
     * @return list<string>
     */
    private static function useCasesFor(string $host): array
    {
        $configured = Config::get('site.use_cases');

        if (! is_array($configured)) {
            return [];
        }

        $slugs = [];

        foreach ($configured as $slug => $hosts) {
            if (is_string($slug) && is_array($hosts) && in_array($host, $hosts, strict: true)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }
}
