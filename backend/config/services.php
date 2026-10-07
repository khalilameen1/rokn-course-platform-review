<?php

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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'connect_timeout_seconds' => (float) env('GOOGLE_CONNECT_TIMEOUT_SECONDS', 3),
        'timeout_seconds' => (float) env('GOOGLE_TIMEOUT_SECONDS', 8),
        // Browser-restricted key used only by the legacy admin map views.
        // When it is absent those views deliberately do not load Google Maps.
        'maps_browser_key' => env('GOOGLE_MAPS_BROWSER_KEY'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        // Set explicitly after checking Meta's currently supported versions.
        // Failing closed is safer than silently pinning a retired API version.
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', ''),
    ],

    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'team_id' => env('APPLE_TEAM_ID'),
        'key_id' => env('APPLE_KEY_ID'),
        'key_file' => env('APPLE_KEY_FILE'),
        'private_key_base64' => env('APPLE_PRIVATE_KEY_BASE64'),
    ],
];
