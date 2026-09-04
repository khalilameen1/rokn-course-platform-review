<?php

return [
    'stream_api_key' => env('BUNNY_STREAM_API_KEY'),
    'library_id' => env('BUNNY_STREAM_LIBRARY_ID'),
    'cdn_hostname' => env('BUNNY_CDN_HOSTNAME'),
    'storage_zone' => env('BUNNY_STORAGE_ZONE'),
    'storage_password' => env('BUNNY_STORAGE_PASSWORD'),
    // Storage assets require their own Pull Zone hostname and token key.
    // Bunny Stream delivery credentials are not interchangeable with them.
    'storage_cdn_hostname' => env('BUNNY_STORAGE_CDN_HOSTNAME'),
    'storage_token_auth_key' => env('BUNNY_STORAGE_TOKEN_AUTH_KEY'),
    'token_auth_key' => env('BUNNY_TOKEN_AUTH_KEY'),
    // Optional Stream webhook Read-Only API key. Events accelerate the same
    // authoritative probe used by the scheduler; they never publish directly.
    'webhook_secret' => env('BUNNY_STREAM_WEBHOOK_SECRET'),
    'connect_timeout_seconds' => (int) env('BUNNY_CONNECT_TIMEOUT_SECONDS', 15),
    'upload_timeout_seconds' => (int) env('BUNNY_UPLOAD_TIMEOUT_SECONDS', 3600),
    'direct_upload_signature_ttl_seconds' => (int) env('BUNNY_DIRECT_UPLOAD_SIGNATURE_TTL_SECONDS', 1800),
    'direct_upload_claim_ttl_hours' => (int) env('BUNNY_DIRECT_UPLOAD_CLAIM_TTL_HOURS', 24),
    'direct_upload_allocation_lease_seconds' => (int) env('BUNNY_DIRECT_UPLOAD_ALLOCATION_LEASE_SECONDS', 120),
    'probe_circuit_failure_threshold' => (int) env('BUNNY_PROBE_CIRCUIT_FAILURE_THRESHOLD', 3),
    'probe_circuit_open_seconds' => (int) env('BUNNY_PROBE_CIRCUIT_OPEN_SECONDS', 60),
];
