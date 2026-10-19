<?php

$recoveryEvidenceDisk = trim((string) env('RECOVERY_EVIDENCE_DISK', ''));

return [
    'horizon_enabled' => (bool) env('HORIZON_ENABLED', false),
    // Detailed launch-readiness checks name every configured provider. Like
    // spatie/laravel-health's results endpoint, details need this secret.
    'health_token' => (string) env('HEALTH_CHECK_TOKEN', ''),
    'queue_heartbeat_key' => env('QUEUE_HEARTBEAT_KEY', 'operations:queue-heartbeat:v1'),
    'queue_heartbeat_required_queues' => array_values(array_unique(array_filter(array_map(
        static fn (string $queue): string => trim($queue),
        explode(',', (string) env(
            'QUEUE_HEARTBEAT_REQUIRED_QUEUES',
            'default,notifications,ai-chat,ai-feedback,media,operations,webhooks'
        ))
    )))),
    'queue_heartbeat_ttl_seconds' => (int) env('QUEUE_HEARTBEAT_TTL_SECONDS', 600),
    'queue_heartbeat_max_age_seconds' => (int) env('QUEUE_HEARTBEAT_MAX_AGE_SECONDS', 180),
    'queue_backlog_limits' => [
        (string) env('REDIS_QUEUE', 'default') => (int) env('QUEUE_BACKLOG_DEFAULT_LIMIT', 1000),
        (string) env('NOTIFICATIONS_QUEUE', 'notifications') => (int) env('QUEUE_BACKLOG_NOTIFICATIONS_LIMIT', 5000),
        (string) env('AI_CHAT_QUEUE', 'ai-chat') => (int) env('QUEUE_BACKLOG_AI_CHAT_LIMIT', 200),
        (string) env('AI_FEEDBACK_QUEUE', 'ai-feedback') => (int) env('QUEUE_BACKLOG_AI_FEEDBACK_LIMIT', 200),
        (string) env('MEDIA_QUEUE', 'media') => (int) env('QUEUE_BACKLOG_MEDIA_LIMIT', 500),
        (string) env('OPERATIONS_QUEUE', 'operations') => (int) env('QUEUE_BACKLOG_OPERATIONS_LIMIT', 500),
        (string) env('WEBHOOK_QUEUE', 'webhooks') => (int) env('QUEUE_BACKLOG_WEBHOOKS_LIMIT', 500),
    ],
    'scheduler_heartbeat_key' => env('SCHEDULER_HEARTBEAT_KEY', 'operations:scheduler-heartbeat:v1'),
    'scheduler_heartbeat_ttl_seconds' => (int) env('SCHEDULER_HEARTBEAT_TTL_SECONDS', 600),
    'scheduler_heartbeat_max_age_seconds' => (int) env('SCHEDULER_HEARTBEAT_MAX_AGE_SECONDS', 180),
    'alert_repeat_minutes' => (int) env('OPERATIONS_ALERT_REPEAT_MINUTES', 30),
    'account_file_cleanup_max_attempts' => (int) env('ACCOUNT_FILE_CLEANUP_MAX_ATTEMPTS', 20),
    'bunny_cleanup_max_attempts' => (int) env('BUNNY_CLEANUP_MAX_ATTEMPTS', 8),
    'store_notification_stale_minutes' => (int) env('STORE_NOTIFICATION_STALE_MINUTES', 10),
    'payment_reconciliation_stale_minutes' => (int) env('PAYMENT_RECONCILIATION_STALE_MINUTES', 45),
    'certificate_recovery_max_attempts' => (int) env('CERTIFICATE_RECOVERY_MAX_ATTEMPTS', 3),
    'certificate_recovery_stale_minutes' => (int) env('CERTIFICATE_RECOVERY_STALE_MINUTES', 5),
    'fcm_circuit_failure_threshold' => (int) env('FCM_CIRCUIT_FAILURE_THRESHOLD', 3),
    'fcm_circuit_open_seconds' => (int) env('FCM_CIRCUIT_OPEN_SECONDS', 60),

    // A player is considered live only while it keeps reporting progress.
    // The wider stale window avoids terminating a session during a short
    // mobile-network hand-off or while Android briefly backgrounds the app.
    'playback_active_window_seconds' => (int) env('PLAYBACK_ACTIVE_WINDOW_SECONDS', 90),
    'playback_stale_after_minutes' => (int) env('PLAYBACK_STALE_AFTER_MINUTES', 10),
    'playback_metrics_days' => (int) env('PLAYBACK_METRICS_DAYS', 7),

    'media_reconcile_status_key' => env('MEDIA_RECONCILE_STATUS_KEY', 'operations:media-reconcile:status:v1'),
    'media_reconcile_lock_key' => env('MEDIA_RECONCILE_LOCK_KEY', 'operations:media-reconcile:lock:v1'),
    'media_reconcile_lock_seconds' => (int) env('MEDIA_RECONCILE_LOCK_SECONDS', 10800),
    'media_reconcile_batch_size' => (int) env('MEDIA_RECONCILE_BATCH_SIZE', 25),
    'media_reconcile_fetch_manifest' => filter_var(
        env('MEDIA_RECONCILE_FETCH_MANIFEST', true),
        FILTER_VALIDATE_BOOL
    ),

    'kashier_reconcile_lock_key' => env(
        'KASHIER_RECONCILE_LOCK_KEY',
        'operations:kashier-reconcile:lock:v1'
    ),
    'kashier_reconcile_lock_seconds' => (int) env('KASHIER_RECONCILE_LOCK_SECONDS', 1800),
    // Pending orders can still be captured late; captured ones can still be
    // refunded or charged back (card-network dispute windows are up to 120
    // days). Webhooks remain the primary signal; this bounds the backstop.
    'kashier_reconcile_pending_window_hours' => (int) env('KASHIER_RECONCILE_PENDING_WINDOW_HOURS', 72),
    'kashier_reconcile_window_days' => (int) env('KASHIER_RECONCILE_WINDOW_DAYS', 120),

    // Signed evidence is written only by verification commands. The dashboard
    // reads it but can neither manufacture evidence nor start a restore.
    'backup_max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),
    'restore_drill_max_age_days' => (int) env('RESTORE_DRILL_MAX_AGE_DAYS', 90),
    'mysql_binary' => env('MYSQL_BINARY', 'mysql'),
    'disaster_recovery_mode' => filter_var(env('DISASTER_RECOVERY_MODE', false), FILTER_VALIDATE_BOOL),
    'recovery_encryption_key_id' => env('RECOVERY_ENCRYPTION_KEY_ID'),
    'recovery_evidence_signing_key' => env('RECOVERY_EVIDENCE_SIGNING_KEY'),
    // Evidence may live on a durable Laravel disk (recommended on Cloud) or
    // on explicit absolute paths for a deliberately mounted shared volume.
    'recovery_evidence_disk' => $recoveryEvidenceDisk,
    'recovery_evidence_path' => env(
        'RECOVERY_EVIDENCE_PATH',
        $recoveryEvidenceDisk !== '' ? 'recovery/latest.json' : storage_path('app/recovery/latest.json')
    ),
    'backup_evidence_path' => env(
        'BACKUP_EVIDENCE_PATH',
        $recoveryEvidenceDisk !== '' ? 'recovery/latest-backup.json' : storage_path('app/recovery/latest-backup.json')
    ),
    'recovery_rpo_minutes' => (int) env('RECOVERY_RPO_MINUTES', 15),
    'recovery_rto_minutes' => (int) env('RECOVERY_RTO_MINUTES', 60),
];
