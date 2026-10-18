<?php

return [
    'account_binding_key' => env('STORE_BILLING_ACCOUNT_BINDING_KEY', env('APP_KEY')),
    // Emails of the App Review demo account and testers allowed to redeem
    // sandbox/test purchases. Empty keeps every account eligible.
    'test_purchase_accounts' => array_values(array_filter(array_map(
        static fn (string $email): string => strtolower(trim($email)),
        explode(',', (string) env('STORE_BILLING_TEST_PURCHASE_ACCOUNTS', ''))
    ))),
    'connect_timeout_seconds' => (float) env('STORE_BILLING_CONNECT_TIMEOUT_SECONDS', 3),
    'timeout_seconds' => (float) env('STORE_BILLING_TIMEOUT_SECONDS', 12),

    'google' => [
        'package_name' => env('GOOGLE_PLAY_PACKAGE_NAME', 'com.rokn'),
        'credentials_file' => env('GOOGLE_PLAY_SERVICE_ACCOUNT_FILE'),
        'credentials_base64' => env('GOOGLE_PLAY_SERVICE_ACCOUNT_BASE64'),
        'rtdn_audience' => env('GOOGLE_PLAY_RTDN_AUDIENCE'),
        'rtdn_service_account_email' => env('GOOGLE_PLAY_RTDN_SERVICE_ACCOUNT_EMAIL'),
    ],

    'apple' => [
        'bundle_id' => env('APPLE_STORE_BUNDLE_ID', 'com.rokn'),
        'issuer_id' => env('APPLE_STORE_ISSUER_ID'),
        'key_id' => env('APPLE_STORE_KEY_ID'),
        'private_key_file' => env('APPLE_STORE_PRIVATE_KEY_FILE'),
        'private_key_base64' => env('APPLE_STORE_PRIVATE_KEY_BASE64'),
        'root_certificate_sha256' => array_values(array_filter(array_map(
            static fn (string $value): string => strtolower(str_replace(':', '', trim($value))),
            explode(',',
                // Apple Root CA - G3, published by Apple PKI. Operators may
                // append a replacement root during a documented rotation.
                '63343abfb89a6a03ebb57e9b3f5fa7be7c4f5c756f3017b3a8c488c3653e9179,'
                .(string) env('APPLE_STORE_ROOT_CERTIFICATE_SHA256', '')
            )
        ))),
    ],
];
