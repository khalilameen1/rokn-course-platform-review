<?php

return [
    // Product analytics and client diagnostics are operational data, not a
    // financial ledger. Keep the raw rows bounded; retain only aggregates for
    // longer trend reporting.
    'client_events_days' => (int) env('RETENTION_CLIENT_EVENTS_DAYS', 30),
    'product_events_days' => (int) env('RETENTION_PRODUCT_EVENTS_DAYS', 180),
    'outbox_delivered_days' => (int) env('RETENTION_OUTBOX_DELIVERED_DAYS', 30),
    'outbox_failed_days' => (int) env('RETENTION_OUTBOX_FAILED_DAYS', 180),
    'playback_completed_days' => (int) env('RETENTION_PLAYBACK_COMPLETED_DAYS', 90),
    'playback_abandoned_days' => (int) env('RETENTION_PLAYBACK_ABANDONED_DAYS', 30),
    'playback_metric_rollups_days' => (int) env('RETENTION_PLAYBACK_METRIC_ROLLUPS_DAYS', 400),
    // Security audit evidence is intentionally longer-lived, but still
    // finite. This setting never applies to orders, bills or wallet ledgers.
    'admin_audit_days' => (int) env('RETENTION_ADMIN_AUDIT_DAYS', 730),
    'student_notifications_days' => (int) env('RETENTION_STUDENT_NOTIFICATIONS_DAYS', 180),
    'support_cases_days' => (int) env('RETENTION_SUPPORT_CASES_DAYS', 365),
    'visitors_days' => (int) env('RETENTION_VISITORS_DAYS', 90),
    'portfolio_drafts_days' => (int) env('RETENTION_PORTFOLIO_DRAFTS_DAYS', 30),
    'project_submission_failed_files_days' => (int) env('RETENTION_PROJECT_SUBMISSION_FAILED_FILES_DAYS', 30),
];
