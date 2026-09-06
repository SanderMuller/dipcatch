<?php declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Config;

/**
 * The use-case landing pages. Slugs and shops come from `config/site.php`.
 *
 * The copy is written out per slug rather than templated, because four pages
 * filled from one string table would be near-duplicates. It stays in literal
 * `__('…')` calls so MarketingTranslationsTest can find it, as it already does
 * for {@see StructuredData}.
 */
final class UseCases
{
    /**
     * @return list<UseCase>
     */
    public static function all(): array
    {
        $configured = Config::get('site.use_cases');

        if (! is_array($configured)) {
            return [];
        }

        $copy = self::copy();

        $cases = [];
        foreach ($configured as $slug => $hosts) {
            if (! is_string($slug) || ! isset($copy[$slug]) || ! is_array($hosts)) {
                continue;
            }

            $cases[] = new UseCase(
                slug: $slug,
                heading: $copy[$slug]['heading'],
                description: $copy[$slug]['description'],
                intro: $copy[$slug]['intro'],
                example: $copy[$slug]['example'],
                hosts: array_values(array_filter($hosts, static fn (mixed $host): bool => is_string($host) && $host !== '')),
                faq: $copy[$slug]['faq'],
            );
        }

        return $cases;
    }

    public static function find(string $slug): ?UseCase
    {
        foreach (self::all() as $case) {
            if ($case->slug === $slug) {
                return $case;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        $configured = Config::get('site.use_cases');

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(array_keys($configured), 'is_string'));
    }

    /**
     * Reads config rather than the copy: this runs at route registration, on
     * every request, and building the copy there would evaluate forty `__()`
     * lookups before the locale middleware has even run.
     */
    public static function slugPattern(): string
    {
        return implode('|', array_map(static fn (string $slug): string => preg_quote($slug, '/'), self::slugs()));
    }

    /**
     * The per-slug copy. Every string is a literal `__()` call on purpose.
     *
     * @return array<string, array{heading: string, description: string, intro: string, example: string, faq: list<array{q: string, a: string}>}>
     */
    private static function copy(): array
    {
        return [
            'groceries' => [
                'heading' => __('Price alerts for your weekly groceries'),
                'description' => __('Track the groceries you buy every week across Albert Heijn, Jumbo, Dirk and six more Dutch supermarkets. DipCatch compares them on price per kilo and tells you when one drops.'),
                'intro' => __('Supermarket prices move every week. The same pack is on bonus at one shop, full price at the next, and the offer is over by the time you notice it. DipCatch watches the items already on your list and tells you which week to buy them.'),
                'example' => __('A 200 g bag of Lay’s Naturel is €2.19 at ah.nl and €1.69 on bonus. That is €8.45 per kilo against €10.95, and it is the cheapest of the four shops tracking it. You get one mail instead of nine open tabs.'),
                'faq' => [
                    ['q' => __('Which supermarkets does this work with?'), 'a' => __('Albert Heijn, Jumbo, Dirk, Lidl, Aldi, SPAR, DekaMarkt, Poiesz and Vomar have their own adapters, including AH Bonus and Dirk promo prices. Other webshops often work too, and you see the result before you confirm.')],
                    ['q' => __('Does it compare different pack sizes?'), 'a' => __('Yes. DipCatch reads the pack size from the page and shows price per kilo or per litre, so a 400 g pack and a 1 kg pack line up honestly.')],
                    ['q' => __('How often are supermarket prices checked?'), 'a' => __('Once when you add the link, then roughly every six hours. Weekly bonus rounds start on a Monday, so a promotion is normally picked up the same morning.')],
                ],
            ],
            'pet-food' => [
                'heading' => __('Price alerts for pet food'),
                'description' => __('Cat food, dog food and litter move in price by the sack. DipCatch tracks them at Zooplus, bol.com and Amazon.nl and compares them on price per kilo.'),
                'intro' => __('Pet food is bought in bulk, which is exactly where unit price stops being obvious. A 10 kg sack is not simply cheaper than two 4 kg sacks, and the ranking changes whenever one shop runs an offer. DipCatch does that sum every few hours so you do not have to do it in the aisle.'),
                'example' => __('A 10 kg sack of dry cat food is €44.99 at zooplus.nl and €39.95 at bol.com. Per kilo that is €4.50 against €4.00, so the same food costs 11 percent less at the second shop. Set a threshold of €40 and you hear about it the next time either one moves.'),
                'faq' => [
                    ['q' => __('Which pet shops work?'), 'a' => __('Zooplus, bol.com and Amazon.nl have their own adapters. Many smaller pet shops publish their product data in a form DipCatch can read as well.')],
                    ['q' => __('Can it compare sack sizes?'), 'a' => __('That is the point of the page. DipCatch shows price per kilo next to the shelf price, so a bulk sack and a small bag can be judged against each other.')],
                    ['q' => __('Does it track litter and treats too?'), 'a' => __('Anything with a product page and a price. If you buy it more than once, it is worth tracking.')],
                ],
            ],
            'coffee' => [
                'heading' => __('Price alerts for coffee and capsules'),
                'description' => __('Beans, pads and capsules at Albert Heijn, Jumbo, bol.com and Amazon.nl, compared on price per cup and watched for the next offer.'),
                'intro' => __('Coffee is the clearest case for a price alert: you buy it on a schedule, you buy the same thing every time, and it goes on offer constantly. The catch is that a box of 40 capsules and a box of 100 are priced to look alike. DipCatch reduces both to a price per cup and watches them.'),
                'example' => __('A box of 40 capsules is €14.99 and a box of 100 of the same capsule is €31.99. That is €0.37 a cup against €0.32, so the larger box wins until the small one goes on offer at €11.99 and takes the lead at €0.30. DipCatch tells you which week that happens.'),
                'faq' => [
                    ['q' => __('Does it work for pads and beans as well as capsules?'), 'a' => __('Yes. Whatever the pack says, DipCatch reads the count or the weight and works out the unit price from it.')],
                    ['q' => __('Can I track the same coffee at more than one shop?'), 'a' => __('Yes, and that is where it earns its keep. Add the same product from several shops and the page shows you the cheapest one right now.')],
                    ['q' => __('Do supermarket bonus prices count?'), 'a' => __('They do. AH Bonus and Dirk promo prices are read as the current price, which is usually the price you actually want to know about.')],
                ],
            ],
            'filters' => [
                'heading' => __('Price alerts for vacuum and water filters'),
                'description' => __('Vacuum bags, water filters and cartridges at bol.com and Amazon.nl. DipCatch remembers what you paid last time, so you do not have to.'),
                'intro' => __('Filters are the opposite of groceries. You buy them once or twice a year, which means you have no idea what they normally cost, and the price can drift by a third between orders without anyone noticing. DipCatch keeps the history and tells you whether today is a good day to reorder.'),
                'example' => __('A four-pack of water filter cartridges was €22.95 in March and is €29.95 today. The 90-day chart on the product page shows the whole run, so you can see that €22.95 comes back every few months and wait for it rather than paying the peak.'),
                'faq' => [
                    ['q' => __('Which shops work for filters?'), 'a' => __('bol.com and Amazon.nl have their own adapters. Manufacturer webshops often work too, as long as the price is in the page and not loaded afterwards with JavaScript.')],
                    ['q' => __('Can I see what a filter used to cost?'), 'a' => __('Yes. Every product has an optional public page with a chart of the cheapest price over the last 90 days, which is the whole reason this use case exists.')],
                    ['q' => __('What if I only reorder once a year?'), 'a' => __('Then set a threshold and forget about it. DipCatch keeps checking and mails you when the price falls past it, however long that takes.')],
                ],
            ],
        ];
    }
}
