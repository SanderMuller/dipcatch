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
        'anonymous_days' => 32,
        'linked_days' => 90,
    ],

    'signup_source_session_key' => 'signup_source',

    /*
    | A first-party cookie with a random ID, for guests in countries whose
    | analytics cookie exemption covers it, so a returning guest counts once
    | per week and month. Off until the exemption is confirmed. The objection
    | cookie and a Global Privacy Control signal stop all counting either way.
    */
    'visitor_cookie' => [
        'enabled' => (bool) env('ANALYTICS_VISITOR_COOKIE', false),
        'name' => 'dipcatch_vid',
        'objection_name' => 'dipcatch_no_count',
        'countries' => ['NL', 'FR'],
        'lifetime_days' => 395,
    ],

    /** Where the objection form returns to, and the query values it keeps. */
    'objection_return_route' => 'privacy',
    'objection_keep_query' => ['lang'],
];
