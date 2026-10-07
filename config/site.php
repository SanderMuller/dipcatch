<?php declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Marketing site
    |--------------------------------------------------------------------------
    |
    | Contact details and shop list for the public pages (homepage, privacy).
    | The marketing copy itself lives in the views, as `__()` strings, so it
    | translates through `lang/nl.json`.
    | The contact address is shown in the footer and the privacy statement;
    | leave it empty to hide the contact link.
    |
    */

    'contact_email' => env('SITE_CONTACT_EMAIL'),

    /**
     * Date the privacy statement last changed, as YYYY-MM-DD. The privacy
     * page shows it and the sitemap emits it as <lastmod>, so both read one
     * value. Set to null to hide the line and drop the sitemap timestamp.
     */
    'privacy_updated_at' => '2026-10-02',

    /** Shown on the terms page, and the date a change is measured from. */
    'terms_updated_at' => '2026-10-02',

    /**
     * The business that runs DipCatch, as the terms and privacy pages name
     * it. Dutch law requires the name, address and KvK number on a web shop
     * or online service. Public registry data, not a secret.
     */
    'operator' => [
        'name' => 'Scode',
        'street' => 'Slinge 26',
        'city' => '9406 EC Assen',
        'kvk' => '61360511',
        'vat' => 'NL002241339B45',
    ],

    /**
     * Shops with a dedicated landing page. One host per brand, plus the
     * Amazon and Zooplus country sites the use-case pages name. Adapter
     * host maps stay on the adapters; a paste still extracts there.
     */
    'supported_hosts' => [
        'ah.nl', 'jumbo.com', 'dirk.nl', 'lidl.nl', 'aldi.nl', 'spar.nl',
        'dekamarkt.nl', 'poiesz-supermarkten.nl', 'vomar.nl', 'bol.com',
        'amazon.nl', 'amazon.com', 'amazon.co.uk',
        'zooplus.nl', 'zooplus.co.uk', 'bitiba.nl',
        'dierapotheker.nl', 'petsplace.nl', 'medpets.nl', 'welkoop.nl',
        'petsathome.com', 'theordinary.com',
        'lookfantastic.com', 'cultbeauty.com', 'ulta.com',
        'mediamarkt.nl', 'expert.nl', 'megekko.nl',
        'intertoys.nl', 'prenatal.nl',
        'hubo.nl', 'toolstation.nl',
        'dm.de', 'fressnapf.de', 'maxizoo.fr', 'willys.se', 'hemkop.se',
    ],

    /**
     * The supported shops the homepage carousel, the shops hub, the footer
     * and each shop page's "compare with" row show: a few market leaders,
     * not every shop. The other supported shops keep their page, their
     * sitemap entry and their place on the use-case pages and in the full
     * list on the shops hub.
     */
    'highlight_hosts' => [
        'ah.nl', 'jumbo.com', 'lidl.nl', 'aldi.nl', 'bol.com', 'amazon.nl',
        'zooplus.nl', 'petsathome.com', 'mediamarkt.nl',
        'dm.de', 'fressnapf.de', 'willys.se',
    ],

    /**
     * Shops people ask about whose prices DipCatch cannot read: they refuse
     * its requests, or load the price with a script after the page opens.
     * Seen on the dates below. Listed on the shops page so a visitor knows
     * before pasting a link. A shop leaves this list when a live check reads
     * its product pages again.
     */
    'unsupported_hosts' => [
        // Challenge page and HTTP 403, 2026-09-25.
        'kruidvat.nl', 'boots.com', 'superdrug.com', 'notino.nl',
        // Drops the connection for DipCatch's fetcher while a browser gets a
        // page, 2026-09-25.
        'etos.nl',
        // "Robot or human?" check on product pages, 2026-09-25.
        'walmart.com',
        // The page loads, but the price comes from a separate API after it
        // opens; the served HTML holds none, 2026-09-25.
        'target.com',
        // HTTP 403 to DipCatch's fetcher from production, while a browser
        // gets the page, 2026-09-30.
        'praxis.nl', 'babypark.nl', 'dreamland.nl',
        // Bot check for every automated request (Vercel), HTTP 429 from
        // production, 2026-09-30.
        'gamma.nl', 'karwei.nl',
        // Challenge page and HTTP 403 from production, 2026-09-30.
        'azerty.nl', 'action.com', 'debijenkorf.nl', 'lego.com',
    ],

    /**
     * Shops with no reader of their own whose prices DipCatch reads from the
     * standard product data on the page, seen working for real users in
     * production. Listed on the shops page next to the shops with a reader.
     * They get no landing page: that page says a reader was written for the
     * shop. A host leaves this list when its offers stop reading.
     */
    'generic_hosts' => [
        // Three or more product pages, all reading, none failing, last read
        // 2026-10-07 (production export).
        'deonlinedrogist.nl', 'koopjesdrogisterij.nl', 'boodschaapje.nl',
        'drogist.nl', 'gezondheidaanhuis.nl', 'gezonderwinkelen.nl',
        'fitnesscandy.nl', 'musclehouse.nl', 'bodyandshapestore.nl',
        'supspace.nl', 'bodyandfit.com', 'barebells.nl',
    ],

    /**
     * Shop names as people say them, keyed by host. The homepage shows the
     * host on the pill and the name in its title attribute, so searchers and
     * assistants find "Albert Heijn" and not only "ah.nl". A host with no
     * entry here falls back to the host itself.
     */
    'shop_names' => [
        'ah.nl' => 'Albert Heijn',
        'jumbo.com' => 'Jumbo',
        'dirk.nl' => 'Dirk',
        'lidl.nl' => 'Lidl',
        'aldi.nl' => 'Aldi',
        'spar.nl' => 'SPAR',
        'dekamarkt.nl' => 'DekaMarkt',
        'poiesz-supermarkten.nl' => 'Poiesz',
        'vomar.nl' => 'Vomar',
        'bol.com' => 'bol.com',
        'amazon.com' => 'Amazon.com',
        'amazon.co.uk' => 'Amazon.co.uk',
        'amazon.nl' => 'Amazon.nl',
        'zooplus.nl' => 'Zooplus',
        'zooplus.co.uk' => 'Zooplus.co.uk',
        'bitiba.nl' => 'Bitiba',
        'dierapotheker.nl' => 'Dierapotheker',
        'petsplace.nl' => 'Pets Place',
        'medpets.nl' => 'Medpets',
        'welkoop.nl' => 'Welkoop',
        'petsathome.com' => 'Pets at Home',
        'etos.nl' => 'Etos',
        'theordinary.com' => 'The Ordinary',
        'lookfantastic.com' => 'Lookfantastic',
        'cultbeauty.com' => 'Cult Beauty',
        'ulta.com' => 'Ulta',
        'walmart.com' => 'Walmart',
        'kruidvat.nl' => 'Kruidvat',
        'boots.com' => 'Boots',
        'superdrug.com' => 'Superdrug',
        'notino.nl' => 'Notino',
        'target.com' => 'Target',
        'mediamarkt.nl' => 'MediaMarkt',
        'expert.nl' => 'Expert',
        'megekko.nl' => 'Megekko',
        'intertoys.nl' => 'Intertoys',
        'prenatal.nl' => 'Prénatal',
        'hubo.nl' => 'Hubo',
        'toolstation.nl' => 'Toolstation',
        'dm.de' => 'dm',
        'fressnapf.de' => 'Fressnapf',
        'maxizoo.fr' => 'Maxi Zoo',
        'willys.se' => 'Willys',
        'hemkop.se' => 'Hemköp',
        'praxis.nl' => 'Praxis',
        'babypark.nl' => 'Babypark',
        'dreamland.nl' => 'Dreamland',
        'gamma.nl' => 'Gamma',
        'karwei.nl' => 'Karwei',
        'azerty.nl' => 'Azerty',
        'action.com' => 'Action',
        'debijenkorf.nl' => 'de Bijenkorf',
        'lego.com' => 'LEGO',
        'deonlinedrogist.nl' => 'De Online Drogist',
        'koopjesdrogisterij.nl' => 'KoopjesDrogisterij',
        'boodschaapje.nl' => 'Boodschaapje',
        'drogist.nl' => 'Drogist.nl',
        'gezondheidaanhuis.nl' => 'Gezondheid aan huis',
        'gezonderwinkelen.nl' => 'Gezonderwinkelen',
        'fitnesscandy.nl' => 'Fitness Candy',
        'musclehouse.nl' => 'MuscleHouse',
        'bodyandshapestore.nl' => 'Body & Shape Store',
        'supspace.nl' => 'Supspace',
        'bodyandfit.com' => 'Body&Fit',
        'barebells.nl' => 'Barebells',
    ],

    /**
     * Hosts of shops with physical stores. The dashboard groups a
     * week's shops into these and online-only ones. Most of them also sell
     * online, so the group says where a shopper can go, not how they shop.
     * A host missing here counts as online only.
     */
    'store_hosts' => [
        // Supermarkets.
        'ah.nl', 'jumbo.com', 'dirk.nl', 'lidl.nl', 'aldi.nl', 'spar.nl', 'plus.nl', 'coop.nl',
        'dekamarkt.nl', 'poiesz-supermarkten.nl', 'vomar.nl', 'hoogvliet.com', 'janlinders.nl', 'ekoplaza.nl',
        'willys.se', 'hemkop.se',
        // Drugstores and variety stores.
        'kruidvat.nl', 'etos.nl', 'hema.nl', 'action.com', 'blokker.nl', 'boots.com', 'superdrug.com', 'dm.de',
        // DIY, garden and pets.
        'praxis.nl', 'gamma.nl', 'karwei.nl', 'hubo.nl', 'toolstation.nl', 'welkoop.nl', 'petsplace.nl', 'petsathome.com',
        'fressnapf.de', 'maxizoo.fr',
        // Electronics, toys, baby and department stores.
        'mediamarkt.nl', 'coolblue.nl', 'expert.nl', 'intertoys.nl', 'prenatal.nl', 'babypark.nl', 'dreamland.nl', 'debijenkorf.nl', 'lego.com',
        // United States.
        'walmart.com', 'target.com', 'ulta.com',
    ],

    /**
     * The public origin the `seo:check-markdown` command reads by default.
     * Cloudflare converts HTML to Markdown at the edge, so the check only
     * means anything against the real site; `--url` overrides it.
     */
    'production_url' => env('SITE_PRODUCTION_URL', 'https://dipcatch.eu'),

    /**
     * Use-case landing pages, keyed by URL slug. The value is the subset of
     * `supported_hosts` that serves the category, so a shop removed above
     * disappears from the pages too. The copy lives in `App\Support\UseCases`
     * as literal `__()` calls, so it stays translatable and findable.
     *
     * The slugs are baked into the route constraint, so run `route:clear`
     * after adding one or the new page 404s while the footer advertises it.
     */
    'use_cases' => [
        'groceries' => [
            'ah.nl', 'jumbo.com', 'dirk.nl', 'lidl.nl', 'aldi.nl', 'spar.nl', 'dekamarkt.nl', 'poiesz-supermarkten.nl', 'vomar.nl',
            'willys.se', 'hemkop.se',
            'amazon.nl', 'amazon.com', 'amazon.co.uk',
        ],
        'pet-food' => [
            'zooplus.nl', 'zooplus.co.uk', 'bitiba.nl',
            'dierapotheker.nl', 'petsplace.nl', 'medpets.nl', 'welkoop.nl',
            'petsathome.com', 'fressnapf.de', 'maxizoo.fr',
            'bol.com', 'amazon.nl',
            'ah.nl', 'jumbo.com',
        ],
        'coffee' => ['ah.nl', 'jumbo.com', 'bol.com', 'amazon.nl', 'amazon.com', 'amazon.co.uk'],
        'filters' => ['bol.com', 'amazon.nl', 'amazon.com', 'amazon.co.uk'],
        // No hosts on purpose. The other entries are product categories, and
        // their hosts drive the "What people track at X" list on each shop
        // page. This one is a way of working, not a thing people track.
        'ask-your-assistant' => [],
        'beauty' => [
            'theordinary.com',
            'lookfantastic.com', 'cultbeauty.com',
            'ulta.com', 'dm.de',
            'bol.com', 'amazon.nl',
            'ah.nl', 'jumbo.com',
        ],
        'electronics' => ['mediamarkt.nl', 'expert.nl', 'megekko.nl', 'bol.com', 'amazon.nl'],
        'toys' => ['intertoys.nl', 'prenatal.nl', 'bol.com', 'amazon.nl'],
        'diy' => ['hubo.nl', 'toolstation.nl', 'bol.com', 'amazon.nl'],
    ],

];
