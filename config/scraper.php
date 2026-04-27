<?php declare(strict_types=1);

return [

    'user_agent' => env('SCRAPER_USER_AGENT', 'DipCatchBot/1.0 (+https://dipcatch.app/bot)'),

    'timeout' => 15,

    'max_redirects' => 5,

    'host' => [
        'min_interval_seconds' => 8,
        'jitter_seconds' => 2,
        'lock_ttl_seconds' => 30,
    ],

    'robots' => [
        'cache_ttl_seconds' => 3600,
    ],

];
