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
     * Hosts on the homepage "Works with" row. Keep this short: one chip per
     * brand a visitor should recognise at a glance. The shops hub lists the rest.
     */
    'homepage_hosts' => [
        'ah.nl', 'jumbo.com', 'dirk.nl', 'lidl.nl', 'aldi.nl', 'spar.nl',
        'dekamarkt.nl', 'poiesz-supermarkten.nl', 'vomar.nl', 'bol.com', 'amazon.nl', 'zooplus.nl',
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
        'praxis.nl' => 'Praxis',
        'babypark.nl' => 'Babypark',
        'dreamland.nl' => 'Dreamland',
        'gamma.nl' => 'Gamma',
        'karwei.nl' => 'Karwei',
        'azerty.nl' => 'Azerty',
        'action.com' => 'Action',
        'debijenkorf.nl' => 'de Bijenkorf',
        'lego.com' => 'LEGO',
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
            'amazon.nl', 'amazon.com', 'amazon.co.uk',
        ],
        'pet-food' => [
            'zooplus.nl', 'zooplus.co.uk', 'bitiba.nl',
            'dierapotheker.nl', 'petsplace.nl', 'medpets.nl', 'welkoop.nl',
            'petsathome.com',
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
            'ulta.com',
            'bol.com', 'amazon.nl',
            'ah.nl', 'jumbo.com',
        ],
        'electronics' => ['mediamarkt.nl', 'expert.nl', 'megekko.nl', 'bol.com', 'amazon.nl'],
        'toys' => ['intertoys.nl', 'prenatal.nl', 'bol.com', 'amazon.nl'],
        'diy' => ['hubo.nl', 'toolstation.nl', 'bol.com', 'amazon.nl'],
    ],

];
