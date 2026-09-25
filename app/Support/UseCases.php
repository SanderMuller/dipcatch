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
                label: $copy[$slug]['label'],
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
     * @return array<string, array{heading: string, label: string, description: string, intro: string, example: string, tips: list<string>, faq: list<array{q: string, a: string}>}>
     */
    private static function copy(): array
    {
        return [
            'groceries' => [
                'heading' => __('Price alerts for your weekly groceries'),
                'label' => __('your weekly groceries'),
                'description' => __('Follow the groceries you buy every week at Albert Heijn, Jumbo, Dirk and six more Dutch supermarkets. DipCatch compares them per kilo and tells you when one drops.'),
                'intro' => __('Supermarket prices move every week. The same pack is on offer at one shop and full price at the next, and the offer is over by the time you notice. DipCatch watches what is already on your list and tells you which week to buy.'),
                'example' => __('A 200 g bag of Lay’s Naturel is €2.19 at ah.nl and €1.69 on bonus. That is €8.45 per kilo against €10.95, and it is the cheapest of the four shops tracking it. You get one mail instead of nine open tabs.'),
                'tips' => [
                    __('Add the shops you actually pass. A weekly bonus is only worth knowing about at a shop you would walk into anyway.'),
                    __('On Pro, pick your price per kilo instead of the price on the shelf when the packs differ per shop.'),
                    __('Track the pack you buy, not the range. "Lay’s Naturel 200 g" is a price; "crisps" is not.'),
                ],
                'faq' => [
                    ['q' => __('Which supermarkets does this work with?'), 'a' => __('Paste a supermarket or webshop link. Most shops work. We set these up ourselves: Albert Heijn, Jumbo, Dirk, Lidl, Aldi, SPAR, DekaMarkt, Poiesz, Vomar, Amazon.co.uk and Amazon.com. That is why AH Bonus and the Dirk deals come through too. Other webshops often work as well, and you see what we found before you save it.')],
                    ['q' => __('Does it compare different pack sizes?'), 'a' => __('Yes. DipCatch reads how much is in the pack and shows the price per kilo or per litre. A 400 g pack and a 1 kg pack then line up honestly.')],
                    ['q' => __('How often are supermarket prices checked?'), 'a' => __('Once when you add the link, then once a day on the free plan and every six hours on Pro. Weekly bonus rounds start on a Monday, so a promotion is normally picked up that day.')],
                    ['q' => __('Will it tell me about a bonus I would have seen anyway?'), 'a' => __('Only if it is a real drop. With no price of your own, the bonus has to be well below what the product usually costs. Set your own price and DipCatch stays quiet until a shop goes below it. DipCatch is not a folder.')],
                    ['q' => __('Can I track a product that is out of stock?'), 'a' => __('Yes. We keep checking the shop, and the product page says whether it was in stock last time we looked. A sold-out week costs you nothing.')],
                    ['q' => __('What happens when a supermarket changes its page?'), 'a' => __('The check fails rather than storing a wrong price, and the shop is marked so you can see it. A price you cannot trust is worse than no price at all.')],
                ],
            ],
            'pet-food' => [
                'heading' => __('Price alerts for pet food'),
                'label' => __('pet food'),
                'description' => __('Cat food, dog food and litter move in price by the sack. DipCatch follows them at Zooplus, Bitiba, Dierapotheker, Pets Place, Medpets, Welkoop, Pets at Home, bol.com, Amazon, Albert Heijn and Jumbo. It compares them per kilo.'),
                'intro' => __('Pet food comes in big bags, and that is where the price per kilo stops being obvious. A 10 kg sack is not automatically cheaper than two 4 kg sacks, and the order changes as soon as one shop runs an offer. DipCatch does that sum every few hours, so you do not have to do it in the aisle.'),
                'example' => __('A 10 kg sack of dry cat food is €44.99 at zooplus.nl and €39.95 at bol.com. Per kilo that is €4.50 against €4.00, so the same food costs 11 percent less at the second shop. Pick €38 as your price and you hear about it when either one gets there.'),
                'tips' => [
                    __('Follow the big sack and the small bag as two products. Then compare them per kilo instead of guessing which one is better value.'),
                    __('Pick a price you would really reorder at. Pet food keeps, so there is no reason to buy at the top of the range.'),
                    __('Watch for the warning on the product page. Two shops can list the same food in different pack sizes.'),
                ],
                'faq' => [
                    ['q' => __('Which pet shops work?'), 'a' => __('Paste a pet-shop or supermarket link. Most shops work. We set up Zooplus, Bitiba, Dierapotheker, Pets Place, Medpets, Welkoop, Pets at Home, bol.com, Amazon, Albert Heijn and Jumbo ourselves. Many smaller pet shops work straight from what is on the page.')],
                    ['q' => __('Can it compare sack sizes?'), 'a' => __('That is the point of the page. DipCatch shows price per kilo next to the shelf price, so a bulk sack and a small bag can be judged against each other.')],
                    ['q' => __('Does it track litter and treats too?'), 'a' => __('Anything with a product page and a price. If you buy it more than once, it is worth tracking.')],
                    ['q' => __('Does it handle subscription or auto-delivery prices?'), 'a' => __('DipCatch reads the price on the product page. A discount that only appears in your basket is not there yet. So read the alert as the normal price, and take your own discount off it.')],
                    ['q' => __('How many shops can I compare per product?'), 'a' => __('Four on the free plan, and as many as you like on Pro. Three is usually enough: the ranking rarely changes below that.')],
                    ['q' => __('Can I get one mail a day rather than one per drop?'), 'a' => __('That is what you get by default. One email a day, grouped per product, at 09:00 in your own time.')],
                ],
            ],
            'coffee' => [
                'heading' => __('Price alerts for coffee and capsules'),
                'label' => __('coffee and capsules'),
                'description' => __('Beans, pads and capsules at Albert Heijn, Jumbo, bol.com and Amazon. Compared on price per cup, and watched for the next offer.'),
                'intro' => __('Coffee is the clearest case for a price alert. You buy it on a schedule, you buy the same thing every time, and it is on offer constantly. The catch is that a box of 40 capsules and a box of 100 are priced to look alike. DipCatch turns both into a price per cup and watches them.'),
                'example' => __('A box of 40 capsules is €14.99 and a box of 100 of the same capsule is €31.99. That is €0.37 a cup against €0.32, so the larger box wins until the small one goes on offer at €11.99 and takes the lead at €0.30. DipCatch tells you which week that happens.'),
                'tips' => [
                    __('On Pro, pick your price per cup. A box of 100 at a good price still beats a box of 40 on offer more often than not.'),
                    __('Add the supermarket and the webshop for the same coffee. They rarely run offers in the same week.'),
                    __('Watch the end date on an offer price. A bonus week that closes tomorrow is not the price you will pay on Friday.'),
                ],
                'faq' => [
                    ['q' => __('Which shops work for coffee?'), 'a' => __('Paste a product link. Most shops work. We set up Albert Heijn, Jumbo, bol.com and the Amazon sites ourselves, Amazon.co.uk and Amazon.com included. Other webshops often work as well, and you see what we found before you save it.')],
                    ['q' => __('Does it work for pads and beans as well as capsules?'), 'a' => __('Yes. Whatever the pack says, DipCatch reads the number or the weight and works out the price per cup, kilo or litre from it.')],
                    ['q' => __('Can I track the same coffee at more than one shop?'), 'a' => __('Yes, and that is where it earns its keep. Add the same product from several shops and the page shows you the cheapest one right now.')],
                    ['q' => __('Do supermarket bonus prices count?'), 'a' => __('They do. AH Bonus and Dirk promo prices are read as the current price, which is usually the price you actually want to know about.')],
                    ['q' => __('Does it work with a shop that only sells beans by subscription?'), 'a' => __('If the page states a price, yes. A price that only appears after you pick a delivery interval usually cannot be read, and you see that before anything is saved.')],
                    ['q' => __('Can I set a target per cup rather than per box?'), 'a' => __('On Pro, yes. You can pick a price per cup, per kilo or per litre, whichever the pack states.')],
                    ['q' => __('Which coffee is worth tracking?'), 'a' => __('The one you reorder without thinking. A coffee you buy once is a choice. A coffee you buy every month is a fixed cost, and that is where watching the price pays for itself.')],
                ],
            ],
            'filters' => [
                'heading' => __('Price alerts for vacuum and water filters'),
                'label' => __('vacuum and water filters'),
                'description' => __('Vacuum bags, water filters and cartridges at bol.com and Amazon in the UK, the US and other country sites. DipCatch remembers what you paid last time, so you do not have to.'),
                'intro' => __('Filters are the opposite of groceries. You buy them once or twice a year, so you have no idea what they normally cost. The price can move by a third between two orders and nobody notices. DipCatch remembers, and tells you whether today is a good day to reorder.'),
                'example' => __('A four-pack of water filter cartridges was €22.95 in March and is €29.95 today. The product page shows the last 90 days. You can see that €22.95 comes back every few months, so you can wait for it instead of paying the top price.'),
                'tips' => [
                    __('Add the filter the day it arrives, not the day you need the next one. The history is what tells you whether today is cheap.'),
                    __('Use the 90-day chart before reordering. A price that has drifted up by a third usually drifts back.'),
                    __('Set the price you want to pay rather than a percentage. You buy these too rarely to know the normal price, but the chart shows you the low one.'),
                ],
                'faq' => [
                    ['q' => __('Which shops work for filters?'), 'a' => __('Paste a product link. Most shops work. We set up bol.com and the Amazon sites ourselves. Webshops run by the maker often work too, as long as the price is on the page itself.')],
                    ['q' => __('Can I see what a filter used to cost?'), 'a' => __('Yes. Every product can get a public page with a graph of the best price over the last 90 days. Share the link with anyone.')],
                    ['q' => __('What if I only reorder once a year?'), 'a' => __('Then pick your price and forget about it. DipCatch keeps checking and tells you when the price gets there, however long that takes.')],
                    ['q' => __('Do manufacturer webshops work?'), 'a' => __('Often. A shop that puts its product details on the page works. A shop where the price only appears a moment later usually does not. You see which it is before you save anything.')],
                    ['q' => __('Can I share the price history with someone?'), 'a' => __('Yes. Every product has an optional public page with the current price per shop and the 90-day chart. It shows nothing about your account.')],
                    ['q' => __('What if the filter is cheaper as a multipack?'), 'a' => __('Follow both and let the price per cartridge decide. A four-pack and a two-pack are the same product at a different price each, and DipCatch shows both.')],
                ],
            ],
            'ask-your-assistant' => [
                'heading' => __('Set up price tracking by asking ChatGPT or Claude'),
                'label' => __('anything you describe to ChatGPT or Claude'),
                'description' => __('Connect DipCatch to Claude or ChatGPT and add a product by describing it. Say what you buy, where you buy it now, and the price you want to pay.'),
                'intro' => __('Adding a product by hand means a tab for every shop and a link copied from each one. If Claude or ChatGPT is already open, ask there. Connect DipCatch once and the assistant finds the pages and adds them for you.'),
                'example' => __('You type one message. Something like: follow this cat food, I buy it at zooplus.nl and bol.com, find two more shops, and tell me when it is under €40. The assistant finds the pages, and DipCatch reads each one back with the name, the price and the pack size. Nothing is saved until you say yes.'),
                'tips' => [
                    __('Type as much of the pack as you know. Hill’s Science Plan Adult 1-6 7 kg points at one product. Cat food leaves the assistant to choose for you, and it will.'),
                    __('Read back what DipCatch found. A link to the 2 kg bag instead of the 7 kg sack looks fine in a chat, and then you follow the wrong price for weeks.'),
                    __('Give a price if you have one. "Tell me when it is under €40" becomes your target, and that price is what sends the alert. Without one, DipCatch tells you when the price falls well below what it usually costs.'),
                ],
                'faq' => [
                    ['q' => __('Which assistants can I use?'), 'a' => __('Claude, and ChatGPT once DipCatch is in its plugin directory. The Connections page in your account shows which of the two is ready, and its Claude button opens Claude with DipCatch already filled in. Another assistant that can connect to outside tools can use the address on that page.')],
                    ['q' => __('Can the assistant find the shops itself?'), 'a' => __('It finds the product pages with whatever browsing it has, then hands each link over. DipCatch does not search the web: it takes a link, fetches that page and reports what it read. For Dutch supermarkets the product page in DipCatch suggests other chains that look like the same pack as well.')],
                    ['q' => __('What can I ask it to do?'), 'a' => __('It can list your products, look one up and show how a price moved. It can follow a new product, add or remove a shop, and ask for a fresh check. It can set your price, rename a product, file it under a category, pick its picture or stop following it. If DipCatch cannot read a shop page, the assistant can keep it as a plain link, and DipCatch tries that page again every week. The assistant only sees your own account.')],
                    ['q' => __('Can it change things without asking me?'), 'a' => __('Yes. It can change your alert, rename a product and remove a shop without checking with you first. DipCatch tells it to ask you before it deletes a product, but the assistant has to follow that itself. So connect one you trust, and disconnect it on the Connections page when you are done. Adding a shop takes two steps. The first saves nothing and shows you what DipCatch read from the page.')],
                    ['q' => __('When does DipCatch send an alert?'), 'a' => __('When the price falls well below what the product usually costs. That is the typical price over the last 30 days, or the first price DipCatch read if the product is new. For a cheap item that means 15 percent, or €3 off a pack of the same size. The percentage is lower on costly items. DipCatch compares per kilo, litre or piece where the pack says how much is in it, so a bigger pack that works out cheaper counts as a drop too. Set your own percentage or amount and it replaces that part of the default.')],
                    ['q' => __('What happens when I set my own price?'), 'a' => __('Then your price decides. DipCatch tells you when a shop reaches it, and says how many to buy if it takes a multi-buy. You hear about it once while the price stays there, and again if it drops lower. The automatic drop alerts switch off for that product, unless you also set a percentage or an amount. On Pro you can set a price per kilo, litre or piece instead.')],
                    ['q' => __('How many shops can one product have?'), 'a' => __('Four on the free plan and as many as you like on Pro. Two shops you already check plus two the assistant finds is a normal starting point.')],
                ],
            ],
            'beauty' => [
                'heading' => __('Price alerts for skincare and makeup'),
                'label' => __('skincare and makeup'),
                'description' => __('CeraVe, La Roche-Posay, Cetaphil, The Ordinary and the rest of the cabinet. Watched at Lookfantastic, Cult Beauty, Ulta, bol.com, Amazon, Albert Heijn and Jumbo. DipCatch compares them per kilo or litre and tells you when a tub drops.'),
                'intro' => __('A serum and a moisturiser run out on a schedule, which is exactly where a shelf price starts to lie. A 454 g tub is not simply cheaper than a 340 g tub, and the ranking changes whenever one shop runs an offer. DipCatch does that sum so you reorder the size that actually costs less.'),
                'example' => __('A 454 g tub of CeraVe moisturising cream is €23.00 at bol.com. Per kilo that is €50.66. The 340 g tub at €18.85 is €55.44 per kilo, so the larger tub wins until the small one goes on offer. Pick your price and you hear about it when either one gets there.'),
                'tips' => [
                    __('Track the tub or bottle you actually finish, not the range. "CeraVe moisturising cream 454 g" is a price; "moisturiser" is not.'),
                    __('On Pro, pick your price per kilo or litre when the packs differ per shop.'),
                    __('Add the drogist and the brand site for the same product. They rarely run offers in the same week.'),
                ],
                'faq' => [
                    ['q' => __('Which beauty shops work?'), 'a' => __('Paste a product link. Most shops work. We set up Lookfantastic, Cult Beauty, Ulta, The Ordinary, bol.com, Amazon, Albert Heijn and Jumbo ourselves. Etos, Kruidvat, Boots, Superdrug, Notino, Walmart, Target and many brand shops do not let us read their price, so those are not in the list. Other webshops often work as well, and you see what we found before you save it.')],
                    ['q' => __('Does it compare different tub sizes?'), 'a' => __('Yes. DipCatch reads how much is in the pack and shows the price per kilo, litre or piece. A 340 g tub and a 454 g tub then line up honestly.')],
                    ['q' => __('Does makeup count, or only skincare?'), 'a' => __('Anything with a product page and a price. A mascara you repurchase, a serum, a moisturiser and a cleanser are all worth tracking if you buy them more than once.')],
                    ['q' => __('Can I track The Ordinary on the brand site?'), 'a' => __('Yes. Paste the theordinary.com product link. DipCatch reads the price on that page, including the Dutch and other country paths.')],
                    ['q' => __('Do supermarket bonus prices count?'), 'a' => __('They do. AH Bonus prices are read as the current price, which is usually the price you actually want to know about.')],
                    ['q' => __('What if the same cream is cheaper as a pump bottle?'), 'a' => __('Follow both and let the price per kilo decide. A 454 g tub and a 454 g pump are the same cream at a different price each, and DipCatch shows both.')],
                ],
            ],
        ];
    }
}
