<?php

return [
    'economics_configured' => trim((string) env('ROKN_NET_USD_PER_PAID_COIN', '')) !== ''
        && trim((string) env('ROKN_AI_COST_SAFETY_MULTIPLIER', '')) !== '',

    /*
     * Net USD retained by Rokn for one purchased coin after payment fees and
     * taxes. Finance should update this whenever coin packages or FX change.
     */
    'net_usd_per_paid_coin' => (float) env('ROKN_NET_USD_PER_PAID_COIN', 0.001),

    /* Covers provider price movement, retries and normal usage variance. */
    'ai_cost_safety_multiplier' => (float) env('ROKN_AI_COST_SAFETY_MULTIPLIER', 2.0),

    /*
     * One operational contract for every newly-created course. The dashboard
     * may switch features and lower message counts by tier; provider budgets
     * remain server-owned so a moderator cannot create an unpriced bill.
     */
    'ai_tiers' => [
        'guided' => [
            'chat_message_limit' => 50,
            'chat_token_budget' => 300000,
            'chat_attachment_max_files' => 2,
            'ai_budget_usd' => .80,
            'request_reserve_usd' => .014,
            'max_output_tokens' => 600,
            'project_feedback_token_budget' => 20000,
            'project_feedback_budget_usd' => .25,
            'project_feedback_reserve_usd' => .02,
        ],
        'mentor' => [
            'chat_message_limit' => 150,
            'chat_token_budget' => 1000000,
            'chat_attachment_max_files' => 3,
            'ai_budget_usd' => 2.50,
            'request_reserve_usd' => .016,
            'max_output_tokens' => 800,
            'project_feedback_token_budget' => 50000,
            'project_feedback_budget_usd' => .75,
            'project_feedback_reserve_usd' => .025,
            'project_followup_message_limit' => 50,
            'project_followup_token_budget' => 250000,
            'project_followup_budget_usd' => .75,
            'project_followup_reserve_usd' => .015,
            'project_followup_attachment_max_files' => 3,
        ],
    ],

    /*
     * Recovery lease for a reservation left behind by a killed queue worker.
     * It must outlive the longest accepted queue delay and provider call.
     */
    'ai_reservation_ttl_seconds' => (int) env('ROKN_AI_RESERVATION_TTL_SECONDS', 1200),

    /*
     * A provider timeout can be billable even though no answer reached Rokn.
     * Do not consume the learner's message allowance, but stop repeated
     * unknown outcomes on this paid enrollment before they become unbounded.
     */
    'ai_unanswered_provider_request_limit' => (int) env(
        'ROKN_AI_UNANSWERED_PROVIDER_REQUEST_LIMIT',
        4
    ),
    'ai_unanswered_provider_window_seconds' => (int) env(
        'ROKN_AI_UNANSWERED_PROVIDER_WINDOW_SECONDS',
        600
    ),
    'ai_provider_exposure_cooldown_seconds' => (int) env(
        'ROKN_AI_PROVIDER_EXPOSURE_COOLDOWN_SECONDS',
        300
    ),
];
