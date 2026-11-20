<?php declare(strict_types=1);

use App\Analytics\Countries\CloudflareCountry;
use App\Analytics\Sections\AuthMiddlewareSection;

/*
| In-house page view analytics (specs/visitor-analytics.md). Everything under
| `App\Analytics` reads its app-specific choices from here, so the core can
| move to a package without a rewrite.
*/
return [
    'enabled' => (bool) env('ANALYTICS_ENABLED', true),

    /** A `ResolvesSection`: decides whether a page is marketing or app. */
    'section_resolver' => AuthMiddlewareSection::class,

    /** A `ResolvesCountry`: the visitor's country, or null. */
    'country_resolver' => CloudflareCountry::class,

    /**
     * Routes whose real path is stored. Every other route stores its URI
     * template, so a token, share slug or record id in a path is never kept.
     */
    'public_path_routes' => ['home', 'privacy', 'support', 'terms', 'pricing', 'shops', 'shop', 'use-case'],

    /** Route name patterns that are never counted. */
    'excluded_routes' => ['bot', 'cashier.*', 'social.*', 'invitation.*', 'app.name-search.photo'],

    /** The cache store for the daily salt; null is the default store. */
    'salt_store' => null,

    'retention' => [
        'anonymous_days' => 30,
        'linked_days' => 90,
    ],

    'signup_source_session_key' => 'signup_source',
];
