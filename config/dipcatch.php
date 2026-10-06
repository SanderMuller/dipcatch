<?php declare(strict_types=1);

use App\PriceAdapters\GenericAdapter;
use App\PriceAdapters\Hosts\AldiAdapter;
use App\PriceAdapters\Hosts\AmazonAdapter;
use App\PriceAdapters\Hosts\BenuAdapter;
use App\PriceAdapters\Hosts\BolAdapter;
use App\PriceAdapters\Hosts\DekaMarktAdapter;
use App\PriceAdapters\Hosts\DierapothekerAdapter;
use App\PriceAdapters\Hosts\DirkAdapter;
use App\PriceAdapters\Hosts\EfarmaAdapter;
use App\PriceAdapters\Hosts\EtosAdapter;
use App\PriceAdapters\Hosts\ExpertAdapter;
use App\PriceAdapters\Hosts\FressnapfAdapter;
use App\PriceAdapters\Hosts\HuboAdapter;
use App\PriceAdapters\Hosts\TomAndCoAdapter;
use App\PriceAdapters\Hosts\IntertoysAdapter;
use App\PriceAdapters\Hosts\JumboAdapter;
use App\PriceAdapters\Hosts\LidlAdapter;
use App\PriceAdapters\Hosts\LookfantasticAdapter;
use App\PriceAdapters\Hosts\MediaMarktAdapter;
use App\PriceAdapters\Hosts\MedpetsAdapter;
use App\PriceAdapters\Hosts\MegekkoAdapter;
use App\PriceAdapters\Hosts\OrdinaryAdapter;
use App\PriceAdapters\Hosts\PetsAtHomeAdapter;
use App\PriceAdapters\Hosts\PetsPlaceAdapter;
use App\PriceAdapters\Hosts\PoieszAdapter;
use App\PriceAdapters\Hosts\PrenatalAdapter;
use App\PriceAdapters\Hosts\SparAdapter;
use App\PriceAdapters\Hosts\ToolstationAdapter;
use App\PriceAdapters\Hosts\UltaAdapter;
use App\PriceAdapters\Hosts\VomarAdapter;
use App\PriceAdapters\Hosts\WalmartAdapter;
use App\PriceAdapters\Hosts\WelkoopAdapter;
use App\PriceAdapters\Hosts\ZooplusAdapter;
use App\PriceAdapters\JsonLdAdapter;
use App\PriceAdapters\MicrodataAdapter;
use App\PriceAdapters\OpenGraphAdapter;
use App\PriceAdapters\ShopifyAdapter;
use App\PriceAdapters\UserSelectorAdapter;

return [

    'admin' => [
        'name' => env('ADMIN_NAME', 'Admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo accounts
    |--------------------------------------------------------------------------
    |
    | Read by FreeAccountSeeder, which never runs in production. The address
    | is an environment value rather than a literal so a developer can seed an
    | account they can receive mail for.
    |
    */

    'demo' => [
        'free_email' => env('DEMO_FREE_EMAIL', 'free@dipcatch.test'),
        'password' => env('DEMO_PASSWORD', 'password'),
    ],

    'scheduler' => [
        // Offers queued per hourly recheck run: the 200 every five minutes
        // this replaced, so the daily capacity stays the same.
        'batch_size' => (int) env('DIPCATCH_SCHEDULER_BATCH_SIZE', 2400),
        'jitter_seconds' => (int) env('DIPCATCH_SCHEDULER_JITTER_SECONDS', 300),
    ],

    'notifications' => [
        'user_hourly_limit' => (int) env('DIPCATCH_NOTIFICATIONS_HOURLY_LIMIT', 30),
    ],

    'fetcher' => [
        /*
         * The one identity: what the shop requests send, what the robots.txt
         * rules are matched against, and what the /bot page publishes.
         *
         * It used to impersonate Safari while /bot told operators we send
         * `DipCatchBot` and that disallowing it in robots.txt would stop us.
         * The rule was matched against the impersonated string, so it was
         * matched on "Mozilla" and the published control did nothing.
         */
        'user_agent' => (string) env('DIPCATCH_FETCHER_USER_AGENT', 'DipCatchBot/1.0 (+https://dipcatch.eu/bot)'),
        'timeout_seconds' => (int) env('DIPCATCH_FETCHER_TIMEOUT', 10),
        // Welkoop's product pages run to 2.05 MB, over the old 2 MB cap. One
        // read in 32 MB and 0.6 s (measured 2026-09-24).
        'body_cap_bytes' => (int) env('DIPCATCH_FETCHER_BODY_CAP_BYTES', 5_000_000),
        'rate_limit_per_minute' => (int) env('DIPCATCH_FETCHER_RATE_LIMIT_PER_MINUTE', 30),
        'robots_cache_seconds' => (int) env('DIPCATCH_FETCHER_ROBOTS_CACHE_SECONDS', 86_400),
        // SSRF guard toggles. Never enable in production.
        'allow_unresolved' => filter_var(env('DIPCATCH_FETCHER_ALLOW_UNRESOLVED', false), FILTER_VALIDATE_BOOLEAN),
        'allow_private_ips' => filter_var(env('DIPCATCH_FETCHER_ALLOW_PRIVATE_IPS', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'shop' => [
        // Health thresholds for the main failure counter (parse / 4xx / block).
        'failing_after' => (int) env('DIPCATCH_SHOP_FAILING_AFTER', 3),
        'dead_after' => (int) env('DIPCATCH_SHOP_DEAD_AFTER', 10),
        // Separate higher tolerance for transient upstream 5xx outages.
        'failing_5xx_after' => (int) env('DIPCATCH_SHOP_FAILING_5XX_AFTER', 10),
        'dead_5xx_after' => (int) env('DIPCATCH_SHOP_DEAD_5XX_AFTER', 30),
    ],

    'reference' => [
        // How long a shop kept as a link waits before it is asked again. A
        // block is a fact about today, not a permanent one — but it changes
        // on the timescale of a shop replatforming, not of an afternoon.
        'retry_every_days' => (int) env('DIPCATCH_REFERENCE_RETRY_EVERY_DAYS', 7),
    ],

    'recheck' => [
        'interval_hours' => (int) env('DIPCATCH_RECHECK_INTERVAL_HOURS', 24),
        // Spread each batch of rechecks over this window. Capped at the SQS
        // `DelaySeconds` ceiling of 900s (15 min) by App\Support\RecheckJitter,
        // so a larger value here has no effect.
        'jitter_minutes' => (int) env('DIPCATCH_RECHECK_JITTER_MINUTES', 30),
    ],

    'drops' => [
        // A drop of at least this many percent below the reference is not
        // notified on one reading. The shop's previous successful reading
        // must qualify too — see App\Actions\Drops\DetectDrop.
        'confirm_above_pct' => (int) env('DIPCATCH_DROPS_CONFIRM_ABOVE_PCT', 40),
        // How long the confirming re-fetch waits before it runs. Long enough
        // for a shop's own cache to settle, short enough that a real promotion
        // still alerts within the hour.
        'confirm_delay_minutes' => (int) env('DIPCATCH_DROPS_CONFIRM_DELAY_MINUTES', 10),
    ],

    'digest' => [
        // Local hour-of-day at which each user's daily digest fires. 24h.
        'send_hour' => (int) env('DIPCATCH_DIGEST_SEND_HOUR', 9),
        // Max age of PriceDropEvents pulled into a single digest, in days.
        // Caps the backlog if a user's mail bounced for a while.
        'lookback_days' => (int) env('DIPCATCH_DIGEST_LOOKBACK_DAYS', 7),
    ],

    // Automatic product categories, see App\Services\TypeSafe\TypeSafeClient.
    'categories' => [
        // Below this path score the category is left empty. The score is
        // computed in CategoryScorer from the answer probabilities; it is
        // not the API's per-answer `confidence` field.
        'min_path_score' => (float) env('DIPCATCH_CATEGORIES_MIN_PATH_SCORE', 0.5),
        // Winner divided by runner-up; below this the answer is a coin flip
        // and the category is left empty.
        'min_separation' => (float) env('DIPCATCH_CATEGORIES_MIN_SEPARATION', 1.5),
        // The getting-started idea is stored only above this probability.
        'min_idea_probability' => (float) env('DIPCATCH_CATEGORIES_MIN_IDEA_PROBABILITY', 0.6),
        // Quality over latency: the call runs after the response is sent.
        // The shop check has its own, shorter timeout.
        'timeout_seconds' => (int) env('DIPCATCH_CATEGORIES_TIMEOUT', 20),
        // Paid calls per account and app-wide per day. Zero lifts a cap.
        'daily_limit_per_user' => (int) env('DIPCATCH_CATEGORIES_DAILY_LIMIT_PER_USER', 50),
        'daily_limit' => (int) env('DIPCATCH_CATEGORIES_DAILY_LIMIT', 2000),
    ],

    // The AI check that a shop sells the same product and pack, see
    // App\Services\TypeSafe\ShopMatchCheck. Every value is a Jev yes-chance.
    'shop_checks' => [
        // The add-shop preview warns below this.
        'warn_below' => (float) env('DIPCATCH_SHOP_CHECKS_WARN_BELOW', 0.5),
        // With the check on, a suggestion whose name matched at least this
        // well is sent to Jev. Below 0.55, the plain name-match floor, it
        // shows only once Jev accepts it.
        'loose_match_from' => (float) env('DIPCATCH_SHOP_CHECKS_LOOSE_MATCH_FROM', 0.35),
        // A suggestion Jev rates below this is hidden, however well its
        // name matched.
        'reject_below' => (float) env('DIPCATCH_SHOP_CHECKS_REJECT_BELOW', 0.3),
        // A suggestion whose name matched only loosely shows once Jev
        // rates it at least this. Web discovery proposes a page from it too.
        'accept_from' => (float) env('DIPCATCH_SHOP_CHECKS_ACCEPT_FROM', 0.6),
        // With the check on, a suggestion whose name matched below this waits
        // for Jev's answer before it shows, rather than showing until Jev
        // rejects it. A borderline name match is where Jev is needed most.
        'hold_below' => (float) env('DIPCATCH_SHOP_CHECKS_HOLD_BELOW', 0.7),
        // A person waits for the add-shop check, so it gets a short timeout
        // and no retry. Jev answered in under a second when measured.
        'timeout_seconds' => (int) env('DIPCATCH_SHOP_CHECKS_TIMEOUT', 5),
        // Candidates checked in one request for one product.
        'max_candidates' => (int) env('DIPCATCH_SHOP_CHECKS_MAX_CANDIDATES', 10),
        // Paid calls per account and app-wide per day, counted apart for each
        // ShopCheckPurpose (add-shop, suggestions, web discovery's two checks,
        // and the alert suggestion), so background checks never use up the warning a person
        // is waiting for. Zero lifts a cap.
        'daily_limit_per_user' => (int) env('DIPCATCH_SHOP_CHECKS_DAILY_LIMIT_PER_USER', 50),
        'daily_limit' => (int) env('DIPCATCH_SHOP_CHECKS_DAILY_LIMIT', 2000),
    ],

    // More shops for a tracked product from a web search, checked twice by
    // Jev with a page read in between. See specs/web-shop-discovery.md.
    'web_discovery' => [
        // Nothing runs without a Serper key either.
        'enabled' => (bool) env('DIPCATCH_WEB_DISCOVERY_ENABLED', true),
        'results_per_search' => (int) env('DIPCATCH_WEB_DISCOVERY_RESULTS', 10),
        'country' => (string) env('DIPCATCH_WEB_DISCOVERY_COUNTRY', 'nl'),
        'language' => (string) env('DIPCATCH_WEB_DISCOVERY_LANGUAGE', 'nl'),
        // A result whose title and snippet Jev rates at least this is read.
        'read_from' => (float) env('DIPCATCH_WEB_DISCOVERY_READ_FROM', 0.3),
        'max_reads_per_product' => (int) env('DIPCATCH_WEB_DISCOVERY_MAX_READS', 8),
        // Reads of one page when the shop rate-limits or fails for a while,
        // the delay when the shop names none, and the cap on any delay.
        'read_attempts' => (int) env('DIPCATCH_WEB_DISCOVERY_READ_ATTEMPTS', 3),
        'retry_fallback_seconds' => (int) env('DIPCATCH_WEB_DISCOVERY_RETRY_FALLBACK', 60),
        'retry_max_seconds' => (int) env('DIPCATCH_WEB_DISCOVERY_RETRY_MAX', 900),
        // It polls more often at first, while the shops come in.
        'poll_fast_seconds' => (int) env('DIPCATCH_WEB_DISCOVERY_POLL_FAST_SECONDS', 3),
        'poll_fast_for_seconds' => (int) env('DIPCATCH_WEB_DISCOVERY_POLL_FAST_FOR', 60),
        // How often, and how long, an open suggestions panel polls while
        // discovery is unfinished.
        'poll_seconds' => (int) env('DIPCATCH_WEB_DISCOVERY_POLL_SECONDS', 15),
        'poll_for_seconds' => (int) env('DIPCATCH_WEB_DISCOVERY_POLL_FOR', 300),
        'search_max_age_days' => (int) env('DIPCATCH_WEB_DISCOVERY_SEARCH_MAX_AGE_DAYS', 90),
        // Paid searches app-wide per day. Zero or less lifts the cap.
        'daily_search_limit' => (int) env('DIPCATCH_WEB_DISCOVERY_DAILY_SEARCH_LIMIT', 300),
        // Klarna pages as a source of shop leads (specs/klarna-shop-leads.md).
        // Switch off when Klarna changes its page or refuses DipCatch.
        'klarna_leads' => (bool) env('DIPCATCH_WEB_DISCOVERY_KLARNA_LEADS', true),
        // Shops looked up per product (also the read cap for lead findings),
        // results per shop sent to the first check, and attempts per Klarna
        // step or lead before it gives up.
        'klarna_leads_per_product' => (int) env('DIPCATCH_WEB_DISCOVERY_KLARNA_LEADS_PER_PRODUCT', 3),
        'lead_results_per_host' => (int) env('DIPCATCH_WEB_DISCOVERY_LEAD_RESULTS_PER_HOST', 3),
        'klarna_attempts' => (int) env('DIPCATCH_WEB_DISCOVERY_KLARNA_ATTEMPTS', 3),
        // Pasted Klarna pages looked up per account per day: each one spends
        // searches from the daily limit every account shares.
        'klarna_pastes_per_day' => (int) env('DIPCATCH_WEB_DISCOVERY_KLARNA_PASTES_PER_DAY', 10),
    ],

    // Hosts that are not a shop a consumer orders from: comparison and review
    // sites, social media, rental and wholesale. A pasted link on one is
    // refused, and web discovery skips it. bcc.nl and maxict.nl are bankrupt
    // shops turned into comparison sites: their pages carry another shop's
    // lowest price as if it were their own (read 2026-09-30).
    'not_a_shop' => [
        'wikipedia.org', 'youtube.com', 'facebook.com', 'instagram.com', 'pinterest.com', 'reddit.com', 'tiktok.com', 'x.com', 'twitter.com',
        'tweakers.net', 'beslist.nl', 'kieskeurig.nl', 'vergelijk.nl', 'idealo.nl', 'idealo.de', 'google.com', 'google.nl', 'trustpilot.com',
        'kassa.bnnvara.nl', 'consumentenbond.nl', 'folders.nl', 'reclamefolder.nl', 'myshopi.com', 'openfoodfacts.org', 'voedingscentrum.nl',
        'supermarktscanner.nl', 'fatsecret.nl', 'beeradvocate.com', 'techradar.com',
        'kisteman-events.nl', 'partyverhuren.nl', 'bidfood.nl', 'makro.nl',
        'bcc.nl', 'maxict.nl',
        // Klarna and PriceRunner (Klarna's comparison site abroad) list other
        // shops' prices. Web discovery reads Klarna pages for those shops.
        'klarna.com', 'pricerunner.com', 'pricerunner.nl',
    ],

    // Price extraction chain. Order is priority — user selectors first, then
    // host-specific, then generic fallback. See AdapterResolver for semantics.
    'adapters' => [
        UserSelectorAdapter::class,
        AldiAdapter::class,
        AmazonAdapter::class,
        BenuAdapter::class,
        BolAdapter::class,
        DekaMarktAdapter::class,
        DierapothekerAdapter::class,
        DirkAdapter::class,
        EfarmaAdapter::class,
        EtosAdapter::class,
        JumboAdapter::class,
        LidlAdapter::class,
        LookfantasticAdapter::class,
        MedpetsAdapter::class,
        OrdinaryAdapter::class,
        PetsAtHomeAdapter::class,
        PetsPlaceAdapter::class,
        PoieszAdapter::class,
        SparAdapter::class,
        UltaAdapter::class,
        VomarAdapter::class,
        WalmartAdapter::class,
        WelkoopAdapter::class,
        ZooplusAdapter::class,
        // Marketed shops read through the structured-data chain; see
        // StructuredDataHostAdapter.
        ExpertAdapter::class,
        HuboAdapter::class,
        IntertoysAdapter::class,
        MediaMarktAdapter::class,
        MegekkoAdapter::class,
        PrenatalAdapter::class,
        ToolstationAdapter::class,
        TomAndCoAdapter::class,
        FressnapfAdapter::class,
        JsonLdAdapter::class,
        ShopifyAdapter::class,
        MicrodataAdapter::class,
        OpenGraphAdapter::class,
        GenericAdapter::class,
    ],

    'chatgpt_plugin_url' => env('DIPCATCH_CHATGPT_PLUGIN_URL'),

    'openai_apps_challenge' => env('DIPCATCH_OPENAI_APPS_CHALLENGE_TOKEN'),

];
