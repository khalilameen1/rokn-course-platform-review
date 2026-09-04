<?php

return [
    // This is the single product declaration consumed by the API, dashboard
    // and launch gate. Credentials decide availability; this list decides
    // which providers Rokn intentionally promises to users.
    'providers' => array_values(array_unique(array_filter(array_map(
        static fn (string $provider): string => strtolower(trim($provider)),
        explode(',', (string) env('SOCIAL_AUTH_PROVIDERS', 'google,tiktok,facebook,apple'))
    )))),
    'recommended_provider' => env('SOCIAL_AUTH_RECOMMENDED_PROVIDER', 'google'),
    'welcome_bonus_coins' => (int) env('WELCOME_BONUS_COINS', 20),
    'legal_notice_version' => env('LEGAL_NOTICE_VERSION', '2026-08-06'),
    'timeout_seconds' => (int) env('SOCIAL_AUTH_TIMEOUT_SECONDS', 10),
    // Account deletion requires a bearer minted immediately after the learner
    // re-verifies the same social identity. Ordinary long-lived sessions are
    // deliberately insufficient for this destructive operation.
    'account_deletion_reauth_seconds' => (int) env('ACCOUNT_DELETION_REAUTH_SECONDS', 300),
    // Override during key rotation if needed. The fallback is the application
    // key and is used only as HMAC material; raw provider identifiers are never
    // persisted in deleted-account reward tombstones.
    'reward_tombstone_hmac_key' => env('REWARD_TOMBSTONE_HMAC_KEY'),
    // Set this to the externally reachable API prefix when APP_URL or reverse
    // proxy detection cannot generate the exact provider callback URL.
    'public_api_url' => env('SOCIAL_AUTH_PUBLIC_API_URL'),
    'return_urls' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SOCIAL_AUTH_RETURN_URLS', 'rokn://auth'))
    ))),
    'tiktok' => [
        'user_info_url' => env('TIKTOK_USER_INFO_URL', 'https://open.tiktokapis.com/v2/user/info/'),
    ],
];
