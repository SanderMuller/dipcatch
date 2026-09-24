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
    | Each default was fetched and read on 2026-09-24. An env value replaces
    | it; an empty one keeps it. amazon, bol, etos and walmart have none: they
    | refuse DipCatch's fetcher (503, 403, no answer, a bot page), so a URL
    | would only report "unreachable". welkoop has none because its product
    | pages exceed the fetcher's body cap. The health check names all five
    | as uncovered, which is true.
    |
    | Keyed on the adapter rather than the host on purpose: an adapter is not a
    | host. AmazonAdapter declares 21 country domains and ZooplusAdapter 18, so
    | one URL per host would be a list of sixty. One URL per adapter proves the
    | adapter still reads a page of its kind, which is what rot destroys first.
    |
    */

    'adapters' => [
        'aldi' => env('CANARY_URL_ALDI') ?: 'https://www.aldi.nl/product/pure-chocolade-1243874.html',
        'amazon' => env('CANARY_URL_AMAZON'),
        'bol' => env('CANARY_URL_BOL'),
        'dekamarkt' => env('CANARY_URL_DEKAMARKT') ?: 'https://www.dekamarkt.nl/producten/dranken-sap-koffie-thee/bier/heineken%20pilsener%20krat/6',
        'dierapotheker' => env('CANARY_URL_DIERAPOTHEKER') ?: 'https://www.dierapotheker.nl/flexadin-advanced-hond/6953/',
        'dirk' => env('CANARY_URL_DIRK') ?: 'https://www.dirk.nl/boodschappen/x/x/x/84109',
        'etos' => env('CANARY_URL_ETOS'),
        'jumbo' => env('CANARY_URL_JUMBO') ?: 'https://www.jumbo.com/producten/hipro-protein-drink-mango-300ml-494984DSL',
        'lidl' => env('CANARY_URL_LIDL') ?: 'https://www.lidl.nl/p/badenia-trendline-koudschuim-matras-bt155-pro/p100153019',
        'lookfantastic' => env('CANARY_URL_LOOKFANTASTIC') ?: 'https://www.cultbeauty.com/p/cerave-moisturising-cream-pot-with-ceramides-for-dry-to-very-dry-skin-454g/11798691/',
        'medpets' => env('CANARY_URL_MEDPETS') ?: 'https://www.medpets.nl/4lazylegs-hondendraagzak?sku=MP9563',
        'ordinary' => env('CANARY_URL_ORDINARY') ?: 'https://theordinary.com/en-nl/niacinamide-10-zinc-1-serum-100436.html',
        'petsathome' => env('CANARY_URL_PETSATHOME') ?: 'https://www.petsathome.com/product/P3333',
        'petsplace' => env('CANARY_URL_PETSPLACE') ?: 'https://www.petsplace.nl/natural-health-dog-adult-hondenbrokken-kip-rijst-12-5-kg-08715207706250',
        'poiesz' => env('CANARY_URL_POIESZ') ?: 'https://webwinkel.poiesz-supermarkten.nl/boodschappen/producten/900127',
        'spar' => env('CANARY_URL_SPAR') ?: 'https://www.spar.nl/lay\'s-chips-naturel-9183397/',
        'ulta' => env('CANARY_URL_ULTA') ?: 'https://www.ulta.com/p/moisturizing-cream-body-face-moisturizer-xlsImpprod3530069?sku=2234849',
        'vomar' => env('CANARY_URL_VOMAR') ?: 'https://www.vomar.nl/producten/voorraadkast/x/x/106908',
        'walmart' => env('CANARY_URL_WALMART'),
        'welkoop' => env('CANARY_URL_WELKOOP'),
        'zooplus' => env('CANARY_URL_ZOOPLUS') ?: 'https://www.zooplus.nl/shop/katten/verzorging/huisapotheek/verdamper/169589?activeVariant=169589.19',

        // Not a host adapter, so the health check does not ask for it, but
        // it is the one reader for a whole shop platform. A named variant:
        // an unnamed one on a three-flavour page is a chooser, not a price.
        'shopify' => env('CANARY_URL_SHOPIFY') ?: 'https://www.prometeus.nl/products/fit-co-protein-bar-10-x-55-g?variant=56124322185598',
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
