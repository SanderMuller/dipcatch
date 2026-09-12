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
    'privacy_updated_at' => '2026-09-12',

    /** Shown on the terms page, and the date a change is measured from. */
    'terms_updated_at' => '2026-09-09',

    /**
     * Shops with a dedicated landing page. One host per brand, plus the
     * Amazon and Zooplus country sites the use-case pages name. Adapter
     * host maps stay on the adapters; a paste still extracts there.
     */
    'supported_hosts' => [
        'ah.nl', 'jumbo.com', 'dirk.nl', 'lidl.nl', 'aldi.nl', 'spar.nl',
        'dekamarkt.nl', 'poiesz.nl', 'vomar.nl', 'bol.com',
        'amazon.nl', 'amazon.com', 'amazon.co.uk',
        'zooplus.nl', 'zooplus.co.uk', 'bitiba.nl',
        'dierapotheker.nl', 'petsplace.nl', 'medpets.nl', 'welkoop.nl',
        'petsathome.com', 'etos.nl', 'theordinary.com',
        'lookfantastic.com', 'cultbeauty.com', 'ulta.com', 'walmart.com',
    ],

    /**
     * Hosts on the homepage "Works with" row. Keep this short: one chip per
     * brand a visitor should recognise at a glance. The shops hub lists the rest.
     */
    'homepage_hosts' => [
        'ah.nl', 'jumbo.com', 'dirk.nl', 'lidl.nl', 'aldi.nl', 'spar.nl',
        'dekamarkt.nl', 'poiesz.nl', 'vomar.nl', 'bol.com', 'amazon.nl', 'zooplus.nl',
        'etos.nl',
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
        'poiesz.nl' => 'Poiesz',
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
            'ah.nl', 'jumbo.com', 'dirk.nl', 'lidl.nl', 'aldi.nl', 'spar.nl', 'dekamarkt.nl', 'poiesz.nl', 'vomar.nl',
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
            'etos.nl', 'theordinary.com',
            'lookfantastic.com', 'cultbeauty.com',
            'ulta.com', 'walmart.com',
            'bol.com', 'amazon.nl',
            'ah.nl', 'jumbo.com',
        ],
    ],

];
