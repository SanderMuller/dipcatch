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
    private const array ARTICLE_NUMBER = ['dierapotheker.nl', 'poiesz-supermarkten.nl', 'vomar.nl'];

    /**
     * @return list<ShopPage>
     */
    public static function all(): array
    {
        $pages = [];

        foreach (SupportedShops::rows() as $row) {
            $pages[] = self::page($row);
        }

        return $pages;
    }

    public static function find(string $slug): ?ShopPage
    {
        foreach (SupportedShops::rows() as $row) {
            if ($row['slug'] === $slug) {
                return self::page($row);
            }
        }

        return null;
    }

    /**
     * Read off the identity rows, not the pages. `slugPattern()` runs this
     * at route registration, before the locale middleware, and
     * `MarketingPages` on every sitemap build. Neither wants the copy.
     *
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_map(static fn (array $row): string => $row['slug'], SupportedShops::rows());
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

    /**
     * @param  array{host: string, favicon: string, name: string, slug: string}  $row
     */
    private static function page(array $row): ShopPage
    {
        ['host' => $host, 'name' => $name] = $row;

        return new ShopPage(
            host: $host,
            name: $name,
            slug: $row['slug'],
            heading: __('Price alerts for :shop', ['shop' => $name]),
            description: __('Track what you buy at :shop and hear about it when the price drops. DipCatch re-checks the page for you and compares :shop against the other shops that sell the same thing.', ['shop' => $name]),
            intro: __('DipCatch keeps an eye on the products you already buy at :shop. It compares them with the other shops that sell the same thing, and tells you when one goes below the price you set.', ['shop' => $name]),
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
            self::summary($host, $name),
            __('We look at every page you follow about every :hours hours, without you opening anything. Pro looks four times as often.', ['hours' => is_numeric($hours) ? (int) $hours : 24]),
        ];

        if (in_array($host, self::BUNDLE_AWARE, strict: true)) {
            $facts[] = __('At :shop, DipCatch reads multi-buy deals such as 2 for €4 and 1+1 free, including their end dates. You see the price per item and how many you need to buy.', ['shop' => $name]);
        } elseif (in_array($host, self::PROMOTION_AWARE, strict: true)) {
            $facts[] = __('An offer price at :shop is read with the date it ends, so a temporary price is never mistaken for the new normal.', ['shop' => $name]);
        }

        if (in_array($host, self::ARTICLE_NUMBER, strict: true)) {
            $facts[] = __('DipCatch reads the article number at :shop, so it can warn you when two shops turn out to sell different packs.', ['shop' => $name]);
        }

        $facts[] = __('Where the page says how much is in the pack, DipCatch compares per kilo, litre or piece. A 200 g bag and a 370 g bag can then be judged against each other.');

        return $facts;
    }

    /**
     * What is particular about reading this shop, and so the line the shops
     * overview shows under its name. Written per host from what its adapter
     * does, so no two cards say the same thing.
     */
    private static function summary(string $host, string $name): string
    {
        return match ($host) {
            'ah.nl' => __('DipCatch reads Albert Heijn from AH’s own product data, not from the web page, so a new page layout does not stop the price coming in.'),
            'jumbo.com' => __('Jumbo’s price comes from the product data on the page, and from the price Jumbo shows on screen when that data is missing.'),
            'dirk.nl' => __('The Dirk offer price is read together with the pack size, so a Dirk deal lines up per kilo against the other supermarkets.'),
            'lidl.nl' => __('Lidl keeps the pack size apart from the price on its pages. DipCatch reads both, so a Lidl deal compares per kilo like any other.'),
            'aldi.nl' => __('Aldi’s product pages show no price in the page itself. DipCatch reads it from the data the page loads, so Aldi works all the same.'),
            'spar.nl' => __('DipCatch reads the SPAR price and the pack size off the product page, so SPAR can be compared per kilo with the big chains.'),
            'dekamarkt.nl' => __('DekaMarkt prices per store. DipCatch reads the price the site shows a visitor who has not picked a store.'),
            'poiesz-supermarkten.nl' => __('The Poiesz webshop is read from the data behind the page. DipCatch picks the product by the number in your link, not a recommended one beside it.'),
            'vomar.nl' => __('Vomar’s webshop is read from the page’s own data, pack size included, so Vomar compares per kilo with the other chains.'),
            'bol.com' => __('DipCatch reads the price bol.com shows on the product page, whichever seller is behind it.'),
            'amazon.nl' => __('Amazon lays out its pages differently per category. DipCatch looks for the price in each of those layouts, in euros on Amazon.nl.'),
            'amazon.com' => __('Amazon.com is read the same way as Amazon.nl, in US dollars, at the price the product page shows.'),
            'amazon.co.uk' => __('Amazon.co.uk is read the same way as Amazon.nl, in pounds, at the price the product page shows.'),
            'zooplus.nl' => __('A Zooplus page often holds several sizes of one food. DipCatch follows the size you picked, so a 2 kg bag and a 12 kg sack stay apart.'),
            'zooplus.co.uk' => __('Zooplus.co.uk is read in pounds. As on Zooplus.nl, DipCatch follows the size you picked on the page.'),
            'bitiba.nl' => __('Bitiba is the budget shop of Zooplus and uses the same pages, so DipCatch reads it the same way, size by size.'),
            'dierapotheker.nl' => __('Dierapotheker shows a lower price when you buy two or more. DipCatch tracks the price of one, for the pack size on the page.'),
            'petsplace.nl' => __('Pets Place often shows an old price struck through beside the real one. DipCatch reads the price you pay.'),
            'medpets.nl' => __('Medpets lists a price for every size on one page. DipCatch tracks the size in your link.'),
            'welkoop.nl' => __('The product data on a Welkoop page holds the list price. DipCatch reads the price the page shows you instead, offers included.'),
            'petsathome.com' => __('Pets at Home shows an Easy Repeat subscription price beside the normal one. DipCatch tracks the normal price, in pounds.'),
            'theordinary.com' => __('The Ordinary’s own shop is read at the price on the product page, including the Dutch and other country versions of the site.'),
            'lookfantastic.com' => __('Lookfantastic puts every size of a product on one page. DipCatch reads the size that is in your link.'),
            'cultbeauty.com' => __('Cult Beauty sets prices per country. DipCatch reads the currency the page shows, pounds, euros or dollars, for the size in your link.'),
            'ulta.com' => __('Ulta is read in US dollars, at the price on the product page for the size in your link.'),
            default => __('We know :shop well, so the price comes straight off the product page, including its offers.', ['shop' => $name]),
        };
    }

    /**
     * @return list<array{q: string, a: string}>
     */
    private static function faq(string $host, string $name): array
    {
        $offerAnswer = match (true) {
            in_array($host, self::BUNDLE_AWARE, strict: true) => __('Yes. For deals such as 2 for €4 or 1+1 free, DipCatch shows the price per item and how many you need to buy.'),
            in_array($host, self::PROMOTION_AWARE, strict: true) => __('Yes. We read the offer price together with the date it runs until, and the product page shows both. So you can see whether a price is a deal or simply the new price.', []),
            default => __('DipCatch reads the price :shop shows on the product page. When that price is an offer, that is the price you get alerted on.', ['shop' => $name]),
        };

        return [
            [
                'q' => __('Can DipCatch track :shop?', ['shop' => $name]),
                'a' => __('Yes. Paste the link to a :shop product page. You see the name, the photo, the price and the pack size before anything is saved.', ['shop' => $name]),
            ],
            [
                'q' => __('Does it see the offer price at :shop?', ['shop' => $name]),
                'a' => $offerAnswer,
            ],
            [
                'q' => __('Do I need an extension, or an account at :shop?', ['shop' => $name]),
                'a' => __('Neither. You paste a link in your browser and DipCatch does the checking. You hear from us in one email a day, under the bell in the app, or in your browser if you switch that on.'),
            ],
            [
                'q' => __('Can I compare :shop against another shop?', ['shop' => $name]),
                'a' => __('That is the whole point. Add the same product at a second shop, and DipCatch shows which one is cheapest right now. Where it can read the pack, it compares per kilo, litre or piece.'),
            ],
            [
                'q' => __('Does DipCatch only work at :shop?', ['shop' => $name]),
                'a' => __('No. Paste a product link from almost any webshop. :shop just gets extra attention from us.', ['shop' => $name]),
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
