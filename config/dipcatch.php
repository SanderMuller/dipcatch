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

];
