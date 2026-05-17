<?php declare(strict_types=1);

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

    'offer' => [
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

];
