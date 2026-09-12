<?php declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Config;

/**
 * The use-case landing pages. Slugs and shops come from `config/site.php`.
 *
 * The copy is written out per slug rather than templated, because five pages
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
                tips: $copy[$slug]['tips'],
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

        return array_values(array_filter(array_keys($configured), is_string(...)));
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
     * @return array<string, array{heading: string, description: string, intro: string, example: string, tips: list<string>, faq: list<array{q: string, a: string}>}>
     */
    private static function copy(): array
    {
        return [
            'groceries' => [
                'heading' => __('Price alerts for your weekly groceries'),
                'description' => __('Track the groceries you buy every week across Albert Heijn, Jumbo, Dirk and six more Dutch supermarkets, plus Amazon in the UK and the US. DipCatch compares them on price per kilo and tells you when one drops.'),
                'intro' => __('Supermarket prices move every week. The same pack is on bonus at one shop, full price at the next, and the offer is over by the time you notice it. DipCatch watches the items already on your list and tells you which week to buy them.'),
                'example' => __('A 200 g bag of Lay’s Naturel is €2.19 at ah.nl and €1.69 on bonus. That is €8.45 per kilo against €10.95, and it is the cheapest of the four shops tracking it. You get one mail instead of nine open tabs.'),
                'tips' => [
                    __('Add the shops you actually pass. A weekly bonus is only worth knowing about at a shop you would walk into anyway.'),
                    __('Set the threshold on price per kilo rather than on the shelf price when the pack size varies between shops.'),
                    __('Track the pack you buy, not the range. "Lay’s Naturel 200 g" is a price; "crisps" is not.'),
                ],
                'faq' => [
                    ['q' => __('Which supermarkets does this work with?'), 'a' => __('Albert Heijn, Jumbo, Dirk, Lidl, Aldi, SPAR, DekaMarkt, Poiesz and Vomar have their own adapters, including AH Bonus and Dirk promo prices. Amazon.co.uk and Amazon.com do too. Other webshops often work too, and you see the result before you confirm.')],
                    ['q' => __('Does it compare different pack sizes?'), 'a' => __('Yes. DipCatch reads the pack size from the page and shows price per kilo or per litre, so a 400 g pack and a 1 kg pack line up honestly.')],
                    ['q' => __('How often are supermarket prices checked?'), 'a' => __('Once when you add the link, then once a day on the free plan and every six hours on Pro. Weekly bonus rounds start on a Monday, so a promotion is normally picked up that day.')],
                    ['q' => __('Will it tell me about a bonus I would have seen anyway?'), 'a' => __('Only if it beats your threshold. DipCatch is not a folder: it stays quiet until a price passes the line you set, which is what keeps a weekly mail worth opening.')],
                    ['q' => __('Can I track a product that is out of stock?'), 'a' => __('Yes. The shop keeps being checked and the product page says whether it was in stock at the last check, so a sold-out week does not lose you the price history.')],
                    ['q' => __('What happens when a supermarket changes its page?'), 'a' => __('The check fails rather than storing a wrong price, and the shop is marked so you can see it. A price you cannot trust is worse than no price at all.')],
                ],
            ],
            'pet-food' => [
                'heading' => __('Price alerts for pet food'),
                'description' => __('Cat food, dog food and litter move in price by the sack. DipCatch tracks them at Zooplus, Bitiba, Dierapotheker, Pets Place, Medpets, Welkoop, Pets at Home, bol.com, Amazon and Albert Heijn and Jumbo, and compares them on price per kilo.'),
                'intro' => __('Pet food is bought in bulk, which is exactly where unit price stops being obvious. A 10 kg sack is not simply cheaper than two 4 kg sacks, and the ranking changes whenever one shop runs an offer. DipCatch does that sum every few hours so you do not have to do it in the aisle.'),
                'example' => __('A 10 kg sack of dry cat food is €44.99 at zooplus.nl and €39.95 at bol.com. Per kilo that is €4.50 against €4.00, so the same food costs 11 percent less at the second shop. Set a threshold of €40 and you hear about it the next time either one moves.'),
                'tips' => [
                    __('Track the big sack and the small bag as separate products, then compare them on price per kilo rather than guessing which is better value.'),
                    __('Set a threshold you would actually reorder at. Pet food keeps, so there is no reason to buy at the top of the range.'),
                    __('Check the article number warning on the product page: two shops can list the same food in different pack sizes.'),
                ],
                'faq' => [
                    ['q' => __('Which pet shops work?'), 'a' => __('Zooplus and Bitiba country sites, Dierapotheker, Pets Place, Medpets, Welkoop, Pets at Home, bol.com, Amazon country sites, Albert Heijn and Jumbo have their own adapters. Many smaller pet shops publish their product data in a form DipCatch can read as well.')],
                    ['q' => __('Can it compare sack sizes?'), 'a' => __('That is the point of the page. DipCatch shows price per kilo next to the shelf price, so a bulk sack and a small bag can be judged against each other.')],
                    ['q' => __('Does it track litter and treats too?'), 'a' => __('Anything with a product page and a price. If you buy it more than once, it is worth tracking.')],
                    ['q' => __('Does it handle subscription or auto-delivery prices?'), 'a' => __('DipCatch reads the price on the product page. A subscription discount applied in the basket is not on that page, so treat the alert as the shelf price and subtract your own discount.')],
                    ['q' => __('How many shops can I compare per product?'), 'a' => __('Four on the free plan, and as many as you like on Pro. Three is usually enough: the ranking rarely changes below that.')],
                    ['q' => __('Can I get one mail a day rather than one per drop?'), 'a' => __('That is the default. The daily digest groups every drop by product and arrives at 09:00 in your own timezone.')],
                ],
            ],
            'coffee' => [
                'heading' => __('Price alerts for coffee and capsules'),
                'description' => __('Beans, pads and capsules at Albert Heijn, Jumbo, bol.com and Amazon in the UK, the US and other country sites, compared on price per cup and watched for the next offer.'),
                'intro' => __('Coffee is the clearest case for a price alert: you buy it on a schedule, you buy the same thing every time, and it goes on offer constantly. The catch is that a box of 40 capsules and a box of 100 are priced to look alike. DipCatch reduces both to a price per cup and watches them.'),
                'example' => __('A box of 40 capsules is €14.99 and a box of 100 of the same capsule is €31.99. That is €0.37 a cup against €0.32, so the larger box wins until the small one goes on offer at €11.99 and takes the lead at €0.30. DipCatch tells you which week that happens.'),
                'tips' => [
                    __('Set the threshold per cup. A box of 100 at a good price still beats a box of 40 on offer more often than not.'),
                    __('Add the supermarket and the webshop for the same coffee. They rarely run offers in the same week.'),
                    __('Watch the end date on an offer price. A bonus week that closes tomorrow is not the price you will pay on Friday.'),
                ],
                'faq' => [
                    ['q' => __('Which shops work for coffee?'), 'a' => __('Albert Heijn, Jumbo, bol.com and Amazon country sites, including Amazon.co.uk and Amazon.com, have their own adapters. Other webshops often work too, and you see the result before you confirm.')],
                    ['q' => __('Does it work for pads and beans as well as capsules?'), 'a' => __('Yes. Whatever the pack says, DipCatch reads the count or the weight and works out the unit price from it.')],
                    ['q' => __('Can I track the same coffee at more than one shop?'), 'a' => __('Yes, and that is where it earns its keep. Add the same product from several shops and the page shows you the cheapest one right now.')],
                    ['q' => __('Do supermarket bonus prices count?'), 'a' => __('They do. AH Bonus and Dirk promo prices are read as the current price, which is usually the price you actually want to know about.')],
                    ['q' => __('Does it work with a shop that only sells beans by subscription?'), 'a' => __('If the page states a price, yes. A price that only appears after you pick a delivery interval usually cannot be read, and you see that before anything is saved.')],
                    ['q' => __('Can I set a target per cup rather than per box?'), 'a' => __('On Pro, yes. A unit price target alerts on the price per cup, per kilo or per litre, whichever the pack states.')],
                    ['q' => __('Which coffee is worth tracking?'), 'a' => __('The one you reorder without thinking. A coffee you buy once is a shopping decision; a coffee you buy monthly is a standing cost, and that is where a threshold pays for itself.')],
                ],
            ],
            'filters' => [
                'heading' => __('Price alerts for vacuum and water filters'),
                'description' => __('Vacuum bags, water filters and cartridges at bol.com and Amazon in the UK, the US and other country sites. DipCatch remembers what you paid last time, so you do not have to.'),
                'intro' => __('Filters are the opposite of groceries. You buy them once or twice a year, which means you have no idea what they normally cost, and the price can drift by a third between orders without anyone noticing. DipCatch keeps the history and tells you whether today is a good day to reorder.'),
                'example' => __('A four-pack of water filter cartridges was €22.95 in March and is €29.95 today. The 90-day chart on the product page shows the whole run, so you can see that €22.95 comes back every few months and wait for it rather than paying the peak.'),
                'tips' => [
                    __('Add the filter the day it arrives, not the day you need the next one. The history is what tells you whether today is cheap.'),
                    __('Use the 90-day chart before reordering. A price that has drifted up by a third usually drifts back.'),
                    __('Set an absolute threshold rather than a percentage. You buy these so rarely that a percentage has nothing to measure against.'),
                ],
                'faq' => [
                    ['q' => __('Which shops work for filters?'), 'a' => __('bol.com and Amazon country sites, including Amazon.co.uk and Amazon.com, have their own adapters. Manufacturer webshops often work too, as long as the price is in the page and not loaded afterwards with JavaScript.')],
                    ['q' => __('Can I see what a filter used to cost?'), 'a' => __('Yes. Every product has an optional public page with a chart of the cheapest price over the last 90 days, which is the whole reason this use case exists.')],
                    ['q' => __('What if I only reorder once a year?'), 'a' => __('Then set a threshold and forget about it. DipCatch keeps checking and mails you when the price falls past it, however long that takes.')],
                    ['q' => __('Do manufacturer webshops work?'), 'a' => __('Often. A shop that publishes its product data in a readable form works; one that loads the price with JavaScript afterwards usually does not. You see which before you confirm.')],
                    ['q' => __('Can I share the price history with someone?'), 'a' => __('Yes. Every product has an optional public page with the current price per shop and the 90-day chart. It shows nothing about your account.')],
                    ['q' => __('What if the filter is cheaper as a multipack?'), 'a' => __('Track both and let the unit price decide. A four-pack and a two-pack are the same product at different prices per cartridge, and DipCatch states both.')],
                ],
            ],
            'ask-your-assistant' => [
                'heading' => __('Set up price tracking by asking ChatGPT or Claude'),
                'description' => __('Connect DipCatch to ChatGPT or Claude and set a product up by describing it. Say what you buy, where you buy it now, and how far the price has to fall before DipCatch should tell you.'),
                'intro' => __('Adding a product by hand is four tabs and four copied links. If ChatGPT or Claude is already open, say it there instead. Connect DipCatch once and the assistant does the looking up and the adding for you.'),
                'example' => __('You type one message: track Hill’s Science Plan Adult 1-6, I already buy it at zooplus.nl and bol.com, find me two more shops, and tell me when it drops 10 percent. The assistant looks up the four product pages. DipCatch reads each one back with the title, the price and the pack size it found, and saves nothing until you say yes.'),
                'tips' => [
                    __('Type as much of the pack as you know. Hill’s Science Plan Adult 1-6 7 kg points at one product. Cat food leaves the assistant to choose for you, and it will.'),
                    __('Check the lines DipCatch reads back. A link to the 2 kg bag instead of the 7 kg sack looks fine in a chat window and costs you a wrong price for weeks.'),
                    __('Ask for a percentage if you do not have a price in mind. Ten percent works the same on a €4 item and a €40 one, so you can give the same answer for everything you track.'),
                ],
                'faq' => [
                    ['q' => __('Which assistants can I use?'), 'a' => __('Any app that speaks MCP. The Connections page in your account opens Claude with DipCatch already filled in and shows the endpoint other clients need, and it says what each assistant requires today.')],
                    ['q' => __('Can the assistant find the shops itself?'), 'a' => __('It finds the product pages with whatever browsing it has, then hands each link over. DipCatch does not search the web: it takes a link, fetches that page and reports what it read. For Dutch supermarkets the product page in DipCatch suggests other chains that look like the same pack as well.')],
                    ['q' => __('What can I ask it to do?'), 'a' => __('List your products, look one up, start tracking a new one, add a shop to it, remove a shop, set a threshold, force a fresh check, read the price history, or stop tracking something. That is the whole set, and every one of them acts only on your own account.')],
                    ['q' => __('Can it change things without asking me?'), 'a' => __('It can set a threshold, remove a shop and delete a product, so connect an assistant you trust and withdraw access on the same page when you are done. Adding is the exception that always takes two steps: DipCatch stores nothing on the first call, which is the one that reports what it read from the page.')],
                    ['q' => __('Does a percentage replace the other alerts?'), 'a' => __('No. A percentage and an amount both apply, and whichever is reached first sends the alert. Set only a percentage and DipCatch keeps a sensible amount in reserve for the price range the product sits in.')],
                    ['q' => __('How many shops can one product have?'), 'a' => __('Four on the free plan and as many as you like on Pro. Two shops you already check plus two the assistant finds is a normal starting point.')],
                ],
            ],
            'beauty' => [
                'heading' => __('Price alerts for skincare and makeup'),
                'description' => __('CeraVe, La Roche-Posay, Cetaphil, The Ordinary and the rest of the cabinet, at Etos, Lookfantastic, Cult Beauty, Ulta, Walmart, The Ordinary, bol.com, Amazon, Albert Heijn and Jumbo. DipCatch compares them on price per 100 ml or 100 g and tells you when a tub drops.'),
                'intro' => __('A serum and a moisturiser run out on a schedule, which is exactly where a shelf price starts to lie. A 454 g tub is not simply cheaper than a 340 g tub, and the ranking changes whenever one shop runs an offer. DipCatch does that sum so you reorder the size that actually costs less.'),
                'example' => __('A 454 g tub of CeraVe moisturising cream is €23.15 at etos.nl. Per 100 g that is €5.10. The 340 g tub at €18.85 is €5.54 per 100 g, so the larger tub wins until the small one goes on offer. Set a threshold and you hear about it the next time either one moves.'),
                'tips' => [
                    __('Track the tub or bottle you actually finish, not the range. "CeraVe moisturising cream 454 g" is a price; "moisturiser" is not.'),
                    __('Set the threshold per 100 ml or 100 g when pack sizes differ between shops.'),
                    __('Add the drogist and the brand site for the same product. They rarely run offers in the same week.'),
                ],
                'faq' => [
                    ['q' => __('Which beauty shops work?'), 'a' => __('Etos, Lookfantastic, Cult Beauty, Ulta, Walmart, The Ordinary, bol.com, Amazon country sites, Albert Heijn and Jumbo have their own adapters. Kruidvat, Boots, Superdrug, Target, Notino and many brand sites block the checker or hide the price behind JavaScript, so those are not listed. Other webshops often work too, and you see the result before you confirm.')],
                    ['q' => __('Does it compare different tub sizes?'), 'a' => __('Yes. DipCatch reads the pack size from the page and shows price per kilo, litre or piece, so a 340 g tub and a 454 g tub line up honestly.')],
                    ['q' => __('Does makeup count, or only skincare?'), 'a' => __('Anything with a product page and a price. A mascara you repurchase, a serum, a moisturiser and a cleanser are all worth tracking if you buy them more than once.')],
                    ['q' => __('Can I track The Ordinary on the brand site?'), 'a' => __('Yes. Paste the theordinary.com product link. DipCatch reads the price on that page, including the Dutch and other country paths.')],
                    ['q' => __('Do supermarket bonus prices count?'), 'a' => __('They do. AH Bonus prices are read as the current price, which is usually the price you actually want to know about.')],
                    ['q' => __('What if the same cream is cheaper as a pump bottle?'), 'a' => __('Track both and let the unit price decide. A 454 g tub and a 454 g pump are the same cream at different prices per 100 g, and DipCatch states both.')],
                ],
            ],
        ];
    }
}
