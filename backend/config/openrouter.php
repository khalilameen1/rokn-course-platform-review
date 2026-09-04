<?php

return [
    'api_key' => env('OPENROUTER_API_KEY'),
    'endpoint' => env('OPENROUTER_ENDPOINT', 'https://openrouter.ai/api/v1/chat/completions'),
    // The paid coach uses the flagship model. OpenRouter owns failover inside
    // the same request so an outage never becomes a second billable call.
    'default_model' => env('OPENROUTER_DEFAULT_MODEL', 'openai/gpt-5.6-sol'),
    'project_model' => env('OPENROUTER_PROJECT_MODEL', 'openai/gpt-5.6-sol'),
    'fallback_models' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('OPENROUTER_FALLBACK_MODELS', 'openai/gpt-5.6-sol,openai/gpt-5.6-terra,openai/gpt-5.6-luna'))
    ))),
    // Course chat is deliberately direct. The prompt controls brevity; this
    // ceiling only prevents a useful answer from being cut in the middle.
    'reasoning_effort' => env('OPENROUTER_REASONING_EFFORT', 'none'),
    'max_tokens' => (int) env('OPENROUTER_MAX_TOKENS', 800),
    'temperature' => (float) env('OPENROUTER_TEMPERATURE', 0.35),
    'timeout_seconds' => (int) env('OPENROUTER_TIMEOUT_SECONDS', 45),
    'connect_timeout_seconds' => (int) env('OPENROUTER_CONNECT_TIMEOUT_SECONDS', 5),
    'stream_read_timeout_seconds' => (int) env('OPENROUTER_STREAM_READ_TIMEOUT_SECONDS', 45),
    'provider_sort' => env('OPENROUTER_PROVIDER_SORT', 'latency'),
    // Do not reduce the live-chat provider pool unless compliance explicitly
    // requires it. The public policy already describes provider processing;
    // launch reliability needs OpenRouter's full multi-provider failover.
    'provider_data_collection' => env('OPENROUTER_PROVIDER_DATA_COLLECTION', 'allow'),
    'provider_zdr' => filter_var(
        env('OPENROUTER_PROVIDER_ZDR', false),
        FILTER_VALIDATE_BOOL
    ),
    'web_search_enabled' => filter_var(
        env('OPENROUTER_WEB_SEARCH_ENABLED', true),
        FILTER_VALIDATE_BOOL
    ),
    'web_search_max_results' => (int) env('OPENROUTER_WEB_SEARCH_MAX_RESULTS', 3),
    'web_search_max_total_results' => (int) env('OPENROUTER_WEB_SEARCH_MAX_TOTAL_RESULTS', 5),
    'billing_circuit_open_seconds' => (int) env('OPENROUTER_BILLING_CIRCUIT_OPEN_SECONDS', 900),
    'global_daily_request_limit' => (int) env('OPENROUTER_GLOBAL_DAILY_REQUEST_LIMIT', 5000),
    'global_daily_token_budget' => (int) env('OPENROUTER_GLOBAL_DAILY_TOKEN_BUDGET', 2100000),
    'global_monthly_token_budget' => (int) env('OPENROUTER_GLOBAL_MONTHLY_TOKEN_BUDGET', 50000000),
    'chat_history_days' => (int) env('OPENROUTER_CHAT_HISTORY_DAYS', 90),
    // OpenRouter PDF parsing is explicit so adding a PDF never silently
    // switches to a paid parser. cloudflare-ai is currently the free parser.
    'pdf_parser_engine' => env('OPENROUTER_PDF_PARSER_ENGINE', 'cloudflare-ai'),
    'attachment_provider_max_bytes' => (int) env('OPENROUTER_ATTACHMENT_PROVIDER_MAX_BYTES', 8388608),
    // Uploading is a reservation, not free permanent storage. Keep enough
    // headroom for retries while bounding abandoned input per account.
    'attachment_staging_max_files_per_user' => (int) env('OPENROUTER_ATTACHMENT_STAGING_MAX_FILES', 12),
    'attachment_staging_max_bytes_per_user' => (int) env('OPENROUTER_ATTACHMENT_STAGING_MAX_BYTES', 67108864),
    // Course-chat context never crosses a new learner session. This keeps the
    // user-visible privacy promise while preserving continuity during a real
    // study session and across lesson swipes.
    'chat_context_session_minutes' => (int) env('OPENROUTER_CHAT_CONTEXT_SESSION_MINUTES', 120),
    // A live-chat request that has not started within a minute is no longer a
    // useful response. The durable reservation remains a separate, longer
    // accounting lease and is released by the stalled-turn reconciler.
    'queue_stale_seconds' => (int) env('OPENROUTER_QUEUE_STALE_SECONDS', 60),
    // A provider response is cached briefly under the same API key. This is a
    // recovery optimization for an identical request, not the correctness
    // boundary: account-level ZDR or edge eviction may disable the cache, so
    // an uncertain call is still quarantined rather than blindly repeated.
    'response_recovery_cache_ttl_seconds' => (int) env(
        'OPENROUTER_RESPONSE_RECOVERY_CACHE_TTL_SECONDS',
        900
    ),
    // An explicit allowlist still wins. Without one, the configured primary,
    // project and fallback models form the allowlist so a valid production
    // model cannot be rejected merely because a second env key was omitted.
    'allowed_models' => (static function (): array {
        $explicit = trim((string) env('OPENROUTER_ALLOWED_MODELS', ''));
        $source = $explicit !== '' ? $explicit : implode(',', [
            (string) env('OPENROUTER_DEFAULT_MODEL', 'openai/gpt-5.6-sol'),
            (string) env('OPENROUTER_PROJECT_MODEL', 'openai/gpt-5.6-sol'),
            (string) env(
                'OPENROUTER_FALLBACK_MODELS',
                'openai/gpt-5.6-sol,openai/gpt-5.6-terra,openai/gpt-5.6-luna'
            ),
        ]);

        return array_values(array_unique(array_filter(array_map(
            'trim',
            explode(',', $source)
        ))));
    })(),
];
