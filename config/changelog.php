<?php declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | What's new
    |--------------------------------------------------------------------------
    |
    | The entries on the in-app "What's new" page (`app.changelog`), in any
    | order: the page sorts them newest first. This is not `CHANGELOG.md`,
    | which CI rewrites on every release.
    |
    | Each entry is a shipped change a user would notice: a new feature, a
    | new shop, a Pro feature, or a fix. The `changelog` skill decides what
    | earns an entry and how it is written. English only, plain text; a
    | blank line in `body` starts a new paragraph.
    |
    | `category` is a `App\Enums\ChangelogCategory` value: feature, shop,
    | pro or fix. `date` is the day it shipped, as YYYY-MM-DD.
    |
    | Optional: `link` (a route name and a button label) points to where the
    | feature lives. `video` is the slug of `public/changelog/<slug>.mp4` and
    | its `.jpg` poster, made with the `changelog-video` skill. `image` (a
    | `src` under `public/` and an `alt`) is a screenshot. An entry has a
    | video or an image, never both.
    |
    */

    'entries' => [
        [
            'date' => '2026-10-05',
            'category' => 'fix',
            'title' => 'No more suggested shops from abroad',
            'body' => "The barcode search sometimes suggested French, German or Slovenian shops. Suggested shops are now only ones that sell to shoppers in the Netherlands.",
        ],
        [
            'date' => '2026-10-04',
            'category' => 'feature',
            'title' => 'More shops under "Also sold at"',
            'body' => "When DipCatch looks for other shops that sell your product, it now searches by barcode too. That finds shops that give the product a different name, which many online drugstores do.\n\nYou can also track prices at eFarma now.",
        ],
        [
            'date' => '2026-10-04',
            'category' => 'fix',
            'title' => 'Poiesz deals now show up',
            'body' => "DipCatch now sees deals at Poiesz. You get the price before the discount and the day the deal ends.\n\n1+1 gratis deals at Poiesz now count toward the price. Before, those products were tracked at the full price.",
        ],
        [
            'date' => '2026-10-03',
            'category' => 'feature',
            'title' => 'Keep a suggested shop as a link',
            'body' => "Some suggested shops, like PLUS and Hoogvliet, can't be price-checked yet. Under \"Also sold at\" they now have an \"Add as link\" button, so the page stays one click away on your product.\n\nA link has no price and never counts as the cheapest. If DipCatch can read the page later, it starts tracking it by itself.",
        ],
        [
            'date' => '2026-10-02',
            'category' => 'pro',
            'title' => 'AI reads a missing pack size',
            'body' => "With Pro and the AI check on, DipCatch now asks the AI when a shop's page doesn't say how much is in the pack. When it's sure, that shop's price per piece or kilo counts like any other, marked \"size checked by AI\".",
            'link' => ['route' => 'product-features.edit', 'label' => 'Open product features'],
        ],
        [
            'date' => '2026-10-02',
            'category' => 'fix',
            'title' => 'No more suggestions for pages that are gone',
            'body' => "Shop suggestions no longer link to supermarket pages a shop has taken down. DipCatch checks Dirk's range every day, and SPAR and Poiesz pages when it suggests them. If you paste a link to a product a shop no longer lists, it now tells you that, instead of saying it couldn't read a price.\n\nDeals on BENU Shop, like 1+1 gratis, now count toward the price.",
        ],
        [
            'date' => '2026-10-02',
            'category' => 'feature',
            'title' => 'Your products in three groups',
            'body' => "Sorted by biggest drop, your products now come in three groups: the ones at your alert price, the ones on discount at a shop, and the rest. A product with a shop deal no longer ends up under \"No discount right now\".",
            'link' => ['route' => 'app.products.index', 'label' => 'Open your products'],
        ],
        [
            'date' => '2026-10-02',
            'category' => 'fix',
            'title' => 'Better suggested alerts during a deal',
            'body' => "When a shop already has a big deal, the suggested alert now sits between that deal and the usual discount, instead of far above what the shop just charged. A shop that is usually much cheaper than the rest no longer sets the bar. DipCatch also reads Dirk's \"was\" prices now.",
        ],
        [
            'date' => '2026-10-02',
            'category' => 'feature',
            'title' => 'Faster shop suggestions',
            'body' => "With Pro, finding more shops for a product is faster now. They come in one by one, so you can look at the first ones while DipCatch keeps searching.",
            'link' => ['route' => 'app.products.create', 'label' => 'Add a product'],
        ],
        [
            'date' => '2026-10-01',
            'category' => 'shop',
            'title' => 'bol.com in your shop suggestions',
            'body' => "DipCatch now suggests bol.com for the products you track, with bol.com's current price. Add it like any other shop.\n\nPasting a bol.com link yourself works now too.",
            'link' => ['route' => 'app.products.index', 'label' => 'Open your products'],
        ],
        [
            'date' => '2026-10-01',
            'category' => 'feature',
            'title' => 'Add a product in three steps',
            'body' => "Adding a product now takes three short steps: the product, the shops to compare, and your alert.\n\nDipCatch also suggests an alert that fits the product, so you don't have to guess a good price. You'll see it on the last step and when you edit a product.",
            'link' => ['route' => 'app.products.create', 'label' => 'Add a product'],
        ],
        [
            'date' => '2026-10-01',
            'category' => 'feature',
            'title' => 'More shop suggestions through Klarna',
            'body' => "With Pro, DipCatch now also finds shops through Klarna, so you get more shop suggestions. When a shop sells a different pack size, you see the price per kilo or litre to compare.\n\nKlarna itself no longer shows up as your cheapest shop, since you can't buy there.",
            'link' => ['route' => 'app.products.index', 'label' => 'Open your products'],
        ],
        [
            'date' => '2026-10-01',
            'category' => 'pro',
            'title' => 'Price per kilo alerts are now free',
            'body' => "You can now set a target price per kilo, litre or piece on the free plan. DipCatch lets you know when any shop goes below it, whatever the pack size.\n\nSaved one earlier, when it was Pro only? It works now.",
            'link' => ['route' => 'app.products.index', 'label' => 'Open your products'],
        ],
        [
            'date' => '2026-09-30',
            'category' => 'feature',
            'title' => 'Check a shop page before you add it',
            'body' => "When you add a shop, you now see its page next to the product you already track, so you can check it's the same thing. Differences in the name are highlighted, and a green badge shows when the barcode or pack matches.",
            'link' => ['route' => 'app.products.index', 'label' => 'Open your products'],
        ],
        [
            'date' => '2026-09-30',
            'category' => 'shop',
            'title' => 'Seven new shops for electronics, DIY and toys',
            'body' => 'DipCatch now reads MediaMarkt, Expert, Megekko, Intertoys, Prénatal, Hubo and Toolstation. Paste a product link from any of them to start tracking it.',
            'link' => ['route' => 'shops', 'label' => 'See all supported shops'],
        ],
        [
            'date' => '2026-09-30',
            'category' => 'feature',
            'title' => 'Is that "was" price real?',
            'body' => "Some shops show a deal as \"was €12.99, now €9.99\" while the product cost €9.99 last week. When DipCatch saw a lower price at that shop in the month before, the product page now tells you.",
        ],
        [
            'date' => '2026-09-30',
            'category' => 'feature',
            'title' => 'Stop suggestions from a shop you never use',
            'body' => "Pick \"Don't suggest\" in the Hide menu on a product page, or in the menu on a dashboard card. That shop then drops out of the suggestions for all your products. Shops you already track keep working as before.\n\nChanged your mind? Settings, Product features lists the shops you hid, each with Show again.",
            'link' => ['route' => 'product-features.edit', 'label' => 'Open Product features'],
        ],
        [
            'date' => '2026-09-30',
            'category' => 'feature',
            'title' => 'Suggested shops on your dashboard',
            'body' => 'The dashboard now lists other shops that sell your products, next to "Worth a look". Each one shows its price and how sure DipCatch is that it\'s the same product. Open the page to check, or press Add to set up the shop.',
            'image' => ['src' => 'changelog/suggested-shops.png', 'alt' => 'The suggested shops card on the dashboard: two products, each with another shop that sells it, its price and how close the match is.'],
            'link' => ['route' => 'app.dashboard', 'label' => 'Go to your dashboard'],
        ],
        [
            'date' => '2026-09-30',
            'category' => 'pro',
            'title' => 'Find more shops that sell your products',
            'body' => 'With the AI shop check on, DipCatch now searches the web for other shops that sell your products. Pages that match show up under "Also sold at" on the product page, where you can add them or hide them.',
            'image' => ['src' => 'changelog/also-sold-at.png', 'alt' => 'The Also sold at panel on a product page, with a shop found on the web and buttons to open, add or hide it.'],
            'link' => ['route' => 'product-features.edit', 'label' => 'Open Product features'],
        ],
        [
            'date' => '2026-09-29',
            'category' => 'feature',
            'title' => 'The pack price comes first',
            'body' => 'A product now shows the price of the pack, with the price per kilo or litre under it, as long as every shop sells the same pack. When the shops sell different sizes, the price per unit comes first, so you can compare them fairly.',
        ],
        [
            'date' => '2026-09-29',
            'category' => 'feature',
            'title' => 'See how much of your plan you use',
            'body' => 'Plan & billing now shows how many products you track and how many you have left, and what your plan gives you for shops per product, price checks and price history. On Free, it also shows what Pro adds.',
            'link' => ['route' => 'app.billing', 'label' => 'Open Plan & billing'],
        ],
        [
            'date' => '2026-09-29',
            'category' => 'feature',
            'title' => 'All your settings in one place',
            'body' => 'Notifications and the AI features now have their own tabs in Settings, next to your profile. Your timezone and currency moved to the profile tab. Old links to the notifications page still work.',
            'link' => ['route' => 'profile.edit', 'label' => 'Open Settings'],
        ],
        [
            'date' => '2026-09-29',
            'category' => 'fix',
            'title' => 'Fewer wrong shop suggestions',
            'body' => "You'll see fewer suggested shops that sell a different product with a similar name.",
        ],
        [
            'date' => '2026-09-29',
            'category' => 'feature',
            'title' => 'Search everything from the header',
            'body' => "The search in the header now finds every product you track, not just the newest eight, with its photo, best price and shop. It finds the settings pages and other parts of the app too.\n\nNothing found? A link under the results lets you tell us what you were looking for.",
            'image' => ['src' => 'changelog/header-search.png', 'alt' => 'The header search with the word settings typed in, listing the settings pages.'],
        ],
        [
            'date' => '2026-09-29',
            'category' => 'feature',
            'title' => 'Use your shopping list from Claude or ChatGPT',
            'body' => 'If you\'ve connected an AI assistant under Connections, it can now read your shopping list, add several products in one go, and take them off again. The Products page also has a new switch that shows only what\'s on your list.',
            'link' => ['route' => 'app.connections', 'label' => 'Connect an assistant'],
        ],
        [
            'date' => '2026-09-29',
            'category' => 'pro',
            'title' => 'Check a new shop before you add it',
            'body' => 'Pro members can switch on an AI check under Settings, Product features. When you add a shop, it compares the page with the shops you already track and warns you if the product or the pack size looks different. It stays off until you turn it on.',
            'link' => ['route' => 'product-features.edit', 'label' => 'Open Product features'],
        ],
        [
            'date' => '2026-09-28',
            'category' => 'feature',
            'title' => 'Ideas for what else to track',
            'body' => 'Most people start with the one product they came for. The dashboard now has a checklist of things you probably buy again and again: toilet paper, pet food, dishwasher tablets, nappies, printer ink and more. An idea ticks itself off once you track a product that fits, and each one tells you which shops work well for it.',
            'image' => ['src' => 'changelog/tracking-ideas.png', 'alt' => 'The checklist of things people buy again and again, grouped as food and drinks, household, personal care and more.'],
            'link' => ['route' => 'app.dashboard', 'label' => 'Go to your dashboard'],
        ],
        [
            'date' => '2026-09-28',
            'category' => 'feature',
            'title' => 'A shopping list for all your shops',
            'body' => "Add a product to your shopping list from its page or its card. The list puts each product under the shop where it's the best buy right now, so you know where to go. Tick items off as you shop.\n\nNot going to one of the shops this week? Skip it, and its products move to the next best shop. When you print the list, it only shows what you still need. You can also tick items off from the list icon in the header.",
            'video' => 'shopping-list',
            'link' => ['route' => 'app.shopping-list', 'label' => 'Open your shopping list'],
        ],
        [
            'date' => '2026-09-27',
            'category' => 'feature',
            'title' => 'See what to buy at each shop',
            'body' => 'On the Products page, pick a shop and switch on "Best buys here". You\'ll see only the products that are the best buy at that shop right now. On the dashboard, the shop name and the number of best buys open the same list.',
            'video' => 'best-buys-here',
            'link' => ['route' => 'app.products.index', 'label' => 'Open your products'],
        ],
    ],

];
