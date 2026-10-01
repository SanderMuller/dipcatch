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
            'date' => '2026-10-01',
            'category' => 'shop',
            'title' => 'bol.com in your shop suggestions',
            'body' => "DipCatch now suggests bol.com for the products you track. It checks bol.com as soon as you add a product or a shop: by barcode when one of your shops shows it, otherwise by name.\n\nThe price you see is bol.com's own, offers included. Add the shop and DipCatch keeps an eye on it like any other.\n\nYou can also paste a bol.com link yourself. That works now too, even on days the bol.com site turns automated checks away.",
            'link' => ['route' => 'app.products.index', 'label' => 'Open your products'],
        ],
        [
            'date' => '2026-10-01',
            'category' => 'feature',
            'title' => 'Add a product in three steps',
            'body' => "Adding a product now walks you through it: first the product, from a shop link or filled in by hand, then more shops to compare, then your alert.\n\nOn the last step DipCatch suggests an alert at the discount this kind of product usually gets, worked out from its normal price, so an offer on today doesn't count twice. With AI help on, Pro also checks how products like it go on sale.",
            'link' => ['route' => 'app.products.create', 'label' => 'Add a product'],
        ],
        [
            'date' => '2026-10-01',
            'category' => 'feature',
            'title' => 'More shop suggestions through Klarna',
            'body' => "With Pro, DipCatch now also looks up your product on Klarna when it searches for more shops. It checks the shops Klarna lists on their own websites and adds them to your shop suggestions. If a shop sells a different pack size, the suggestion says so and compares the price per kilo or litre.\n\nPasted a Klarna link? You'll see which shops it lists. Klarna no longer counts as your cheapest shop, since you can't buy there.",
            'link' => ['route' => 'app.products.index', 'label' => 'Open your products'],
        ],
        [
            'date' => '2026-10-01',
            'category' => 'pro',
            'title' => 'Price per kilo alerts are now free',
            'body' => "You can now set a target price per kilo, litre or piece on the free plan. DipCatch tells you when any shop reaches it, whatever size the pack is.\n\nSaved one earlier, when it said Pro only? It works now. With that price set, DipCatch stops the automatic drop alerts for that product, so your price decides.",
            'link' => ['route' => 'app.products.index', 'label' => 'Open your products'],
        ],
        [
            'date' => '2026-09-30',
            'category' => 'feature',
            'title' => 'Check a shop page before you add it',
            'body' => "When you add a shop, the preview now shows the page next to the product you already track. Hover the photo to zoom in, or click it to see every photo on the page at full size.\n\nDipCatch highlights the words that differ between the two names, and a green badge says when the barcode or the pack matches. Suggested shops on the dashboard get the same side-by-side view.",
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
            'body' => 'A shop can show a deal as "was €12.99, now €9.99" while the product already cost €9.99 last week. When DipCatch saw a lower price at that shop in the 30 days before a deal, the product page now tells you, right under the deal: "Shop says it was €12.99. Lowest here in the 30 days before: €9.99."',
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
            'body' => 'Now and then a suggested shop sold a different product that just had a similar name. A product in a different pack size now counts as a weaker match, so you\'ll see fewer of these.',
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
