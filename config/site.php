<?php declare(strict_types=1);

$amazonHosts = [
    'amazon.com', 'amazon.co.uk', 'amazon.de', 'amazon.nl', 'amazon.fr',
    'amazon.es', 'amazon.it', 'amazon.ie', 'amazon.se', 'amazon.pl',
    'amazon.ca', 'amazon.com.au', 'amazon.co.jp', 'amazon.in', 'amazon.com.mx',
    'amazon.com.br', 'amazon.com.be', 'amazon.ae', 'amazon.sa', 'amazon.com.tr',
    'amazon.sg',
];

$zooplusHosts = [
    'zooplus.nl', 'zooplus.be', 'zooplus.de', 'zooplus.fr', 'zooplus.it',
    'zooplus.es', 'zooplus.at', 'zooplus.ie', 'zooplus.pt', 'zooplus.fi',
    'zooplus.lu', 'zooplus.com', 'zooplus.co.uk',
];

$bitibaHosts = [
    'bitiba.nl', 'bitiba.be', 'bitiba.de', 'bitiba.fr', 'bitiba.it',
];

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
    'privacy_updated_at' => '2026-09-10',

    /** Shown on the terms page, and the date a change is measured from. */
    'terms_updated_at' => '2026-09-09',

    /**
     * Shops with a dedicated adapter or data source, shown as logos on the
     * homepage and the first-run dashboard.
     *
     * Amazon hosts stay in sync with AmazonAdapter::hosts(); Zooplus and
     * Bitiba hosts stay in sync with ZooplusAdapter::hosts().
     */
    'supported_hosts' => [
        'ah.nl', 'jumbo.com', 'dirk.nl', 'lidl.nl', 'aldi.nl', 'spar.nl',
        'dekamarkt.nl', 'poiesz.nl', 'vomar.nl', 'bol.com',
        ...$amazonHosts,
        ...$zooplusHosts,
        ...$bitibaHosts,
        'dierapotheker.nl', 'petsplace.nl', 'medpets.nl', 'medpets.be', 'welkoop.nl',
        'petsathome.com', 'etos.nl', 'theordinary.com',
        'lookfantastic.com', 'cultbeauty.com', 'ulta.com', 'walmart.com',
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
        'amazon.de' => 'Amazon.de',
        'amazon.nl' => 'Amazon.nl',
        'amazon.fr' => 'Amazon.fr',
        'amazon.es' => 'Amazon.es',
        'amazon.it' => 'Amazon.it',
        'amazon.ie' => 'Amazon.ie',
        'amazon.se' => 'Amazon.se',
        'amazon.pl' => 'Amazon.pl',
        'amazon.ca' => 'Amazon.ca',
        'amazon.com.au' => 'Amazon.com.au',
        'amazon.co.jp' => 'Amazon.co.jp',
        'amazon.in' => 'Amazon.in',
        'amazon.com.mx' => 'Amazon.com.mx',
        'amazon.com.br' => 'Amazon.com.br',
        'amazon.com.be' => 'Amazon.be',
        'amazon.ae' => 'Amazon.ae',
        'amazon.sa' => 'Amazon.sa',
        'amazon.com.tr' => 'Amazon.com.tr',
        'amazon.sg' => 'Amazon.sg',
        'zooplus.nl' => 'Zooplus',
        'zooplus.be' => 'Zooplus.be',
        'zooplus.de' => 'Zooplus.de',
        'zooplus.fr' => 'Zooplus.fr',
        'zooplus.it' => 'Zooplus.it',
        'zooplus.es' => 'Zooplus.es',
        'zooplus.at' => 'Zooplus.at',
        'zooplus.ie' => 'Zooplus.ie',
        'zooplus.pt' => 'Zooplus.pt',
        'zooplus.fi' => 'Zooplus.fi',
        'zooplus.lu' => 'Zooplus.lu',
        'zooplus.com' => 'Zooplus.com',
        'zooplus.co.uk' => 'Zooplus.co.uk',
        'bitiba.nl' => 'Bitiba',
        'bitiba.be' => 'Bitiba.be',
        'bitiba.de' => 'Bitiba.de',
        'bitiba.fr' => 'Bitiba.fr',
        'bitiba.it' => 'Bitiba.it',
        'dierapotheker.nl' => 'Dierapotheker',
        'petsplace.nl' => 'Pets Place',
        'medpets.nl' => 'Medpets',
        'medpets.be' => 'Medpets.be',
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
            'amazon.com', 'amazon.co.uk',
        ],
        'pet-food' => [
            ...$zooplusHosts,
            ...$bitibaHosts,
            'dierapotheker.nl', 'petsplace.nl', 'medpets.nl', 'medpets.be', 'welkoop.nl',
            'petsathome.com',
            'bol.com',
            ...$amazonHosts,
            'ah.nl', 'jumbo.com',
        ],
        'coffee' => ['ah.nl', 'jumbo.com', 'bol.com', ...$amazonHosts],
        'filters' => ['bol.com', ...$amazonHosts],
        'beauty' => [
            'etos.nl', 'theordinary.com',
            'lookfantastic.com', 'cultbeauty.com',
            'ulta.com', 'walmart.com',
            'bol.com',
            ...$amazonHosts,
            'ah.nl', 'jumbo.com',
        ],
    ],

];
