<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    // Set APPLE_CLIENT_SECRET, or leave it empty and give the key trio below
    // instead — the provider then mints the secret per request from the .p8
    // file. APPLE_PRIVATE_KEY is an absolute path to that file, never the key
    // material itself. `SocialProvider::isConfigured()` accepts either route.
    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'client_secret' => env('APPLE_CLIENT_SECRET'),
        'key_id' => env('APPLE_KEY_ID'),
        'team_id' => env('APPLE_TEAM_ID'),
        'private_key' => env('APPLE_PRIVATE_KEY'),
        'redirect' => env('APPLE_REDIRECT_URI'),
    ],

    // TypeSafe, the model behind automatic product categories. An empty key
    // hides the feature: the settings switch is not rendered and nothing is
    // sent. Never commit a real key.
    'typesafe' => [
        'key' => (string) env('TYPESAFE_API_KEY', ''),
    ],

    // Serper, Google results over an API: finds more shops for a tracked
    // product by its name. An empty key switches web shop discovery off.
    'serper' => [
        'key' => (string) env('SERPER_API_KEY', ''),
    ],

    // bol.com's affiliate product feed, over FTPS. The server only answers
    // an IP address whitelisted in the affiliate portal. An empty username
    // switches the feed import off. Never commit the real credentials.
    'bol' => [
        'feed' => [
            'host' => (string) env('BOL_FEED_HOST', 'apm-feed.unftp.bol.com'),
            'username' => (string) env('BOL_USERNAME', ''),
            'password' => (string) env('BOL_PASSWORD', ''),
        ],
        // The Marketing Catalog API: products, offers and search by barcode
        // or name. No IP whitelist. An empty client id switches it off.
        'api' => [
            'client_id' => (string) env('BOL_CLIENT_ID', ''),
            'client_secret' => (string) env('BOL_CLIENT_SECRET', ''),
        ],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
