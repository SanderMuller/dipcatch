<?php declare(strict_types=1);

use Spatie\FailedJobMonitor\Notification;
use Spatie\FailedJobMonitor\Notifiable;

return [

    'notification' => Notification::class,

    'notifiable' => Notifiable::class,

    'notificationFilter' => null,

    'channels' => explode(',', (string) env('FAILED_JOB_CHANNELS', 'mail')),

    'mail' => [
        'to' => array_filter(explode(',', (string) env('FAILED_JOB_MONITOR_NOTIFIABLE', (string) env('ADMIN_EMAIL', '')))),
    ],

    'slack' => [
        'webhook_url' => env('FAILED_JOB_SLACK_WEBHOOK_URL'),
    ],
];
