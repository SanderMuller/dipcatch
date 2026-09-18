<?php declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Adapter canary
    |--------------------------------------------------------------------------
    |
    | One known product URL per host adapter. A daily run fetches each one and
    | asserts that the adapter still claims the page, still reads a price, and
    | reads a price that has not moved implausibly since the last good run.
    |
    | Keyed on the adapter rather than the host on purpose: an adapter is not a
    | host. AmazonAdapter declares 21 country domains and ZooplusAdapter 18, so
    | one URL per host would be a list of sixty. One URL per adapter proves the
    | adapter still reads a page of its kind, which is what rot destroys first.
    |
    */

    'adapters' => [
        'aldi' => env('CANARY_URL_ALDI'),
        'amazon' => env('CANARY_URL_AMAZON'),
        'bol' => env('CANARY_URL_BOL'),
        'dekamarkt' => env('CANARY_URL_DEKAMARKT'),
        'dierapotheker' => env('CANARY_URL_DIERAPOTHEKER'),
        'dirk' => env('CANARY_URL_DIRK'),
        'etos' => env('CANARY_URL_ETOS'),
        'jumbo' => env('CANARY_URL_JUMBO'),
        'lidl' => env('CANARY_URL_LIDL'),
        'lookfantastic' => env('CANARY_URL_LOOKFANTASTIC'),
        'medpets' => env('CANARY_URL_MEDPETS'),
        'ordinary' => env('CANARY_URL_ORDINARY'),
        'petsathome' => env('CANARY_URL_PETSATHOME'),
        'petsplace' => env('CANARY_URL_PETSPLACE'),
        'poiesz' => env('CANARY_URL_POIESZ'),
        'spar' => env('CANARY_URL_SPAR'),
        'ulta' => env('CANARY_URL_ULTA'),
        'vomar' => env('CANARY_URL_VOMAR'),
        'walmart' => env('CANARY_URL_WALMART'),
        'welkoop' => env('CANARY_URL_WELKOOP'),
        'zooplus' => env('CANARY_URL_ZOOPLUS'),
    ],

    /*
    | A reading this far from the last good one, in either direction, is rot.
    | The canary product is one nobody buys, so a few percent of drift is
    | normal and a halving is not. It catches an adapter that reads the wrong
    | element after a redesign, as long as the wrong element is far from the
    | real price.
    */
    'price_move_pct' => (int) env('CANARY_PRICE_MOVE_PCT', 50),

    // A daily run may miss one day. Two means the command stopped.
    'stale_after_hours' => (int) env('CANARY_STALE_AFTER_HOURS', 36),

    // Consecutive unreachable runs before the check fails rather than warns.
    'unreachable_fail_runs' => (int) env('CANARY_UNREACHABLE_FAIL_RUNS', 5),

    /*
    | The canary fetches real shop pages, so it runs where the scheduler runs
    | and nowhere else. A test or a local run must never touch a real shop.
    */
    'environments' => ['production'],

];
