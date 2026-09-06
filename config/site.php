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
    'privacy_updated_at' => '2026-09-02',

    /**
     * Shops with a dedicated adapter or data source, shown as logos on the
     * homepage and the first-run dashboard.
     */
    'supported_hosts' => [
        'ah.nl', 'jumbo.com', 'dirk.nl', 'lidl.nl', 'aldi.nl', 'spar.nl',
        'dekamarkt.nl', 'poiesz.nl', 'vomar.nl', 'bol.com', 'amazon.nl', 'zooplus.nl',
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
        'amazon.nl' => 'Amazon.nl',
        'zooplus.nl' => 'Zooplus',
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
        'groceries' => ['ah.nl', 'jumbo.com', 'dirk.nl', 'lidl.nl', 'aldi.nl', 'spar.nl', 'dekamarkt.nl', 'poiesz.nl', 'vomar.nl'],
        'pet-food' => ['zooplus.nl', 'bol.com', 'amazon.nl'],
        'coffee' => ['ah.nl', 'jumbo.com', 'bol.com', 'amazon.nl'],
        'filters' => ['bol.com', 'amazon.nl'],
    ],

];
