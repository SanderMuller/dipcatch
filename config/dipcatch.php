<?php declare(strict_types=1);

use App\PriceAdapters\UserSelectorAdapter;
use App\PriceAdapters\Hosts\AmazonAdapter;
use App\PriceAdapters\Hosts\BolAdapter;
use App\PriceAdapters\Hosts\DirkAdapter;
use App\PriceAdapters\Hosts\JumboAdapter;
use App\PriceAdapters\Hosts\LidlAdapter;
use App\PriceAdapters\Hosts\ZooplusAdapter;
use App\PriceAdapters\JsonLdAdapter;
use App\PriceAdapters\MicrodataAdapter;
use App\PriceAdapters\OpenGraphAdapter;
use App\PriceAdapters\GenericAdapter;

return [

    'admin' => [
        'name' => env('ADMIN_NAME', 'Admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    'scheduler' => [
        'batch_size' => (int) env('DIPCATCH_SCHEDULER_BATCH_SIZE', 200),
        'jitter_seconds' => (int) env('DIPCATCH_SCHEDULER_JITTER_SECONDS', 300),
    ],

    'notifications' => [
        'user_hourly_limit' => (int) env('DIPCATCH_NOTIFICATIONS_HOURLY_LIMIT', 30),
    ],

    'fetcher' => [
        'user_agent' => (string) env('DIPCATCH_FETCHER_USER_AGENT', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15'),
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

    'recheck' => [
        'interval_hours' => (int) env('DIPCATCH_RECHECK_INTERVAL_HOURS', 6),
        'jitter_minutes' => (int) env('DIPCATCH_RECHECK_JITTER_MINUTES', 30),
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

    // Price extraction chain. Order is priority — user selectors first, then
    // host-specific, then generic fallback. See AdapterResolver for semantics.
    'adapters' => [
        UserSelectorAdapter::class,
        AmazonAdapter::class,
        BolAdapter::class,
        DirkAdapter::class,
        JumboAdapter::class,
        LidlAdapter::class,
        ZooplusAdapter::class,
        JsonLdAdapter::class,
        MicrodataAdapter::class,
        OpenGraphAdapter::class,
        GenericAdapter::class,
    ],

];
