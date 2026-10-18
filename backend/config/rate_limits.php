<?php

declare(strict_types=1);

return [
    'api_read_identity_per_minute' => (int) env('API_READ_IDENTITY_PER_MINUTE', 240),
    // Deliberately generous for campuses, workplaces and shared mobile NATs;
    // still finite so rotating fake bearer tokens cannot create infinite keys.
    'api_read_ip_per_minute' => (int) env('API_READ_IP_PER_MINUTE', 1200),
    'api_write_identity_per_minute' => (int) env('API_WRITE_IDENTITY_PER_MINUTE', 90),
    'api_write_ip_per_minute' => (int) env('API_WRITE_IP_PER_MINUTE', 450),
    // Sign-in is social only (Google, Apple, TikTok): the credential is a
    // provider-signed token, so the per-IP ceiling bounds floods, not
    // guessing. Egyptian mobile carriers put thousands of learners behind one
    // CGNAT address and a sign-in costs about three requests, so the ceiling
    // matches the shared read ceiling rather than a per-household figure.
    // Per-identity limits stay tight.
    'auth_ip_per_minute' => (int) env('AUTH_IP_PER_MINUTE', 1200),
    'auth_ip_per_hour' => (int) env('AUTH_IP_PER_HOUR', 20000),
    // Browser redirects can legitimately repeat, while a per-order bucket
    // prevents one payment from being replayed without punishing shared NATs.
    'kashier_callback_order_per_minute' => (int) env('KASHIER_CALLBACK_ORDER_PER_MINUTE', 30),
    'kashier_callback_ip_per_minute' => (int) env('KASHIER_CALLBACK_IP_PER_MINUTE', 120),
    // Kashier may retry webhooks in bursts. These defaults remain generous for
    // provider traffic and add both order and IP ceilings for forged floods.
    'kashier_webhook_order_per_minute' => (int) env('KASHIER_WEBHOOK_ORDER_PER_MINUTE', 30),
    'kashier_webhook_ip_per_minute' => (int) env('KASHIER_WEBHOOK_IP_PER_MINUTE', 300),
    'kashier_webhook_ip_per_hour' => (int) env('KASHIER_WEBHOOK_IP_PER_HOUR', 2000),
    'payment_reconcile_identity_per_minute' => (int) env(
        'PAYMENT_RECONCILE_IDENTITY_PER_MINUTE',
        20
    ),
    'operational_spike_15m' => (int) env('RATE_LIMIT_OPERATIONAL_SPIKE_15M', 1000),
];
