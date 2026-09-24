<?php declare(strict_types=1);

use App\PriceAdapters\GenericAdapter;
use App\PriceAdapters\Hosts\AldiAdapter;
use App\PriceAdapters\Hosts\AmazonAdapter;
use App\PriceAdapters\Hosts\BolAdapter;
use App\PriceAdapters\Hosts\DekaMarktAdapter;
use App\PriceAdapters\Hosts\DierapothekerAdapter;
use App\PriceAdapters\Hosts\DirkAdapter;
use App\PriceAdapters\Hosts\EtosAdapter;
use App\PriceAdapters\Hosts\JumboAdapter;
use App\PriceAdapters\Hosts\LidlAdapter;
use App\PriceAdapters\Hosts\LookfantasticAdapter;
use App\PriceAdapters\Hosts\MedpetsAdapter;
use App\PriceAdapters\Hosts\OrdinaryAdapter;
use App\PriceAdapters\Hosts\PetsAtHomeAdapter;
use App\PriceAdapters\Hosts\PetsPlaceAdapter;
use App\PriceAdapters\Hosts\PoieszAdapter;
use App\PriceAdapters\Hosts\SparAdapter;
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
        'batch_size' => (int) env('DIPCATCH_SCHEDULER_BATCH_SIZE', 200),
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
        'body_cap_bytes' => (int) env('DIPCATCH_FETCHER_BODY_CAP_BYTES', 2_000_000),
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
        // Max users dispatched per scheduler tick. Bounds the 09:00 burst
        // across timezones so the mailer isn't slammed.
        'batch_size' => (int) env('DIPCATCH_DIGEST_BATCH_SIZE', 500),
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
        // Quality over latency: the call runs after the response is sent.
        'timeout_seconds' => (int) env('DIPCATCH_CATEGORIES_TIMEOUT', 20),
        // Paid calls per account and app-wide per day. Zero lifts a cap.
        'daily_limit_per_user' => (int) env('DIPCATCH_CATEGORIES_DAILY_LIMIT_PER_USER', 50),
        'daily_limit' => (int) env('DIPCATCH_CATEGORIES_DAILY_LIMIT', 2000),
    ],

    // Price extraction chain. Order is priority — user selectors first, then
    // host-specific, then generic fallback. See AdapterResolver for semantics.
    'adapters' => [
        UserSelectorAdapter::class,
        AldiAdapter::class,
        AmazonAdapter::class,
        BolAdapter::class,
        DekaMarktAdapter::class,
        DierapothekerAdapter::class,
        DirkAdapter::class,
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
        JsonLdAdapter::class,
        ShopifyAdapter::class,
        MicrodataAdapter::class,
        OpenGraphAdapter::class,
        GenericAdapter::class,
    ],

    'chatgpt_plugin_url' => env('DIPCATCH_CHATGPT_PLUGIN_URL'),

    'openai_apps_challenge' => env('DIPCATCH_OPENAI_APPS_CHALLENGE_TOKEN'),

];
