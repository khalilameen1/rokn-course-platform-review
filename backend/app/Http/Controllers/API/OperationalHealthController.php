<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ProductionCapabilityService;
use App\Services\AppReleasePolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class OperationalHealthController extends Controller
{
    public function __construct(
        private readonly ProductionCapabilityService $capabilities,
        private readonly AppReleasePolicyService $releasePolicy,
    ) {}

    private const CRITICAL_TABLES = [
        'users',
        'api_tokens',
        'social_accounts',
        'courses',
        'course_modules',
        'course_sections',
        'lessons',
        'course_enrollments',
        'course_access_plans',
        'orders',
        'wallet_transactions',
        'project_submissions',
        'student_notifications',
        'lesson_media_states',
        'playback_sessions',
        'social_oauth_attempts',
        'store_purchases',
    ];

    private const CRITICAL_COLUMNS = [
        'users' => ['profile_revision'],
        'api_tokens' => [
            'token', 'user_id', 'issued_at', 'expired_at', 'session_id',
            'device_id', 'platform', 'device_class', 'app_version', 'app_build',
            'auth_provider', 'auth_provider_user_id', 'last_used_at', 'revoked_at',
        ],
        'social_accounts' => ['user_id', 'provider', 'provider_user_id'],
        'social_oauth_attempts' => [
            'state_hash',
            'completion_hash',
            'code_challenge',
            'nonce_hash',
            'encrypted_completion_code',
            'encrypted_session_response',
            'completion_processing_at',
            'completion_claim_id',
        ],
        'packages' => ['is_active', 'direct_enabled'],
        'course_access_plans' => [
            'project_followup_message_limit',
            'project_followup_token_budget',
            'project_followup_budget_usd',
            'project_followup_reserve_usd',
        ],
        'watching_logs' => [
            'playback_session_id',
            'playback_session_started_at',
            'last_playback_sequence',
        ],
        'student_section_progress' => ['completed_at'],
    ];

    private const LAUNCH_TABLES = [
        'ai_entitlement_usages',
        'ai_usage_events',
        'project_feedback_threads',
        'project_feedback_messages',
        'course_chat_turns',
        'notification_campaigns',
        'wallet_credit_lots',
        'wallet_debit_allocations',
        'financial_entitlement_holds',
        'payment_reconciliation_checkpoints',
        'payment_reconciliation_findings',
        'financial_anomalies',
        'coupon_redemptions',
        'store_notification_events',
        'user_whatsapp_connections',
        'whatsapp_link_tokens',
        'product_feature_flags',
        'admin_audit_logs',
        'operational_incidents',
        // Course editing is isolated in a published/draft graph. A deployment
        // missing either half can still serve the catalogue while every
        // moderator edit fails, so it is not launch-ready.
        'course_authoring_revisions',
        'course_authoring_revision_entities',
    ];

    private const LAUNCH_COLUMNS = [
        'course_enrollments' => [
            'access_plan_id', 'access_plan_order_id', 'access_plan_snapshot',
        ],
        'ai_usage_events' => ['reservation_expires_at'],
        'wallet_transactions' => [
            'public_id', 'direction', 'category', 'bucket', 'amount',
            'paid_amount', 'reward_amount', 'balance_after',
            'paid_balance_after', 'reward_balance_after',
            'idempotency_key', 'occurred_at',
        ],
        'users' => [
            'profile_revision', 'wallet_coins', 'wallet_purchased_coins',
            'wallet_reward_coins',
        ],
        'orders' => [
            'gateway_gross_amount', 'gateway_fee_amount', 'gateway_net_amount',
        ],
        'settings' => ['ai_plan_policy', 'direct_checkout_discount_percent'],
        'user_device_tokens' => ['device_os', 'device_id'],
    ];

    public function live(): JsonResponse
    {
        $time = now()->toIso8601String();

        return response()->json([
            'status' => 'ok',
            'success' => true,
            'message' => 'Service is live',
            'data' => [
                'health_status' => 'ok',
                'time' => $time,
            ],
            'time' => $time,
        ]);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->databaseIsReady(),
            'critical_schema' => $this->criticalSchemaIsReady(),
            'social_oauth_storage' => $this->tableExists('social_oauth_attempts'),
            'identity_contract' => $this->capabilities->socialHandoffIsReady(),
            'cache' => $this->cacheIsReady(),
        ];

        // Traffic readiness answers one question only: can this instance serve
        // the app now? Cache and OAuth handoff are independently degradable;
        // making either one a load-balancer gate turns a provider incident into
        // a blank guest catalogue. Launch readiness below remains the strict
        // all-capabilities gate used before a release.
        $ready = $checks['database']
            && $checks['critical_schema']
            && $checks['social_oauth_storage'];

        $status = $ready ? 'ready' : 'unavailable';
        $time = now()->toIso8601String();

        return response()->json([
            'status' => $status,
            'success' => $ready,
            'message' => $ready ? 'Service is ready' : 'Service is unavailable',
            'data' => [
                'health_status' => $status,
                'checks' => $checks,
                'time' => $time,
            ],
            'checks' => $checks,
            'time' => $time,
        ], $ready ? 200 : 503);
    }

    /**
     * Deployment/launch gate. Unlike traffic readiness, this deliberately
     * fails when a configured product capability is incomplete. Load
     * balancers must use /health/ready, not this endpoint.
     */
    public function launchReady(): JsonResponse
    {
        $report = $this->capabilities->report();
        $mobileRelease = $this->releasePolicy->launchReadiness();
        $launchChannels = array_values((array) ($report['launch_channels'] ?? []));
        $checks = [
            'database' => $this->databaseIsReady(),
            'critical_schema' => $this->criticalSchemaIsReady(),
            'product_schema' => $this->launchSchemaIsReady(),
            'social_oauth_storage' => $this->tableExists('social_oauth_attempts'),
            'identity_contract' => $this->capabilities->socialHandoffIsReady(),
            'cache' => $this->cacheIsReady(),
            'bunny_stream' => (bool) data_get($report, 'capabilities.bunny.stream.ready'),
            'bunny_upload' => (bool) data_get($report, 'capabilities.bunny.upload.ready'),
            'bunny_playback' => (bool) data_get($report, 'capabilities.bunny.playback.ready'),
            'bunny_signing' => (bool) data_get($report, 'capabilities.bunny.signing.ready'),
            'bunny_assets' => (bool) data_get($report, 'capabilities.bunny.assets.ready'),
            'payment' => (bool) data_get($report, 'capabilities.payment.ready'),
            'ai' => (bool) data_get($report, 'capabilities.ai.ready'),
            'mail' => (bool) data_get($report, 'capabilities.mail.ready'),
            'push' => (bool) data_get($report, 'capabilities.push.ready'),
            'social_callbacks' => (bool) data_get($report, 'capabilities.social.callbacks.ready'),
            'social_handoff' => (bool) data_get($report, 'capabilities.social.handoff.ready'),
            'app_links' => (bool) data_get($report, 'capabilities.app_links.ready'),
            'queue' => (bool) data_get($report, 'capabilities.queue.ready'),
            'recovery' => (bool) data_get($report, 'capabilities.recovery.ready'),
            'mobile_release' => $mobileRelease['ready'],
        ];
        if (in_array(AppReleasePolicyService::CHANNEL_DIRECT, $launchChannels, true)) {
            $checks['payment_kashier'] = (bool) data_get($report, 'capabilities.payment.kashier.ready');
        }
        if (in_array(AppReleasePolicyService::CHANNEL_PLAY, $launchChannels, true)) {
            $checks['payment_google_play'] = (bool) data_get($report, 'capabilities.payment.google_play.ready');
        }
        if (in_array(AppReleasePolicyService::CHANNEL_APP_STORE, $launchChannels, true)) {
            $checks['payment_app_store'] = (bool) data_get($report, 'capabilities.payment.app_store.ready');
        }
        if (array_intersect([
            AppReleasePolicyService::CHANNEL_DIRECT,
            AppReleasePolicyService::CHANNEL_PLAY,
        ], $launchChannels) !== []) {
            $checks['app_links_android'] = (bool) data_get($report, 'capabilities.app_links.android.ready');
        }
        if (in_array(AppReleasePolicyService::CHANNEL_APP_STORE, $launchChannels, true)) {
            $checks['app_links_apple'] = (bool) data_get($report, 'capabilities.app_links.apple.ready');
        }
        foreach ((array) data_get($report, 'capabilities.social.declared_providers', []) as $provider) {
            $checks['social_'.$provider] = (bool) data_get($report, "capabilities.social.{$provider}.ready");
        }
        $optionalChecks = collect(['google', 'tiktok', 'apple', 'facebook'])
            ->reject(fn (string $provider): bool => array_key_exists('social_'.$provider, $checks))
            ->mapWithKeys(fn (string $provider): array => [
                'social_'.$provider => (bool) data_get($report, "capabilities.social.{$provider}.ready"),
            ])
            ->all();
        foreach ([
            'payment_kashier' => 'capabilities.payment.kashier.ready',
            'payment_google_play' => 'capabilities.payment.google_play.ready',
            'payment_app_store' => 'capabilities.payment.app_store.ready',
            'app_links_android' => 'capabilities.app_links.android.ready',
            'app_links_apple' => 'capabilities.app_links.apple.ready',
        ] as $name => $path) {
            if (!array_key_exists($name, $checks)) {
                $optionalChecks[$name] = (bool) data_get($report, $path);
            }
        }
        $ready = !in_array(false, $checks, true);

        $status = $ready ? 'launch_ready' : 'launch_blocked';
        $time = now()->toIso8601String();

        return response()->json([
            'status' => $status,
            'success' => $ready,
            'message' => $ready ? 'Launch checks passed' : 'Launch checks failed',
            'data' => [
                'health_status' => $status,
                'checks' => $checks,
                'optional_checks' => $optionalChecks,
                'mobile_release' => $mobileRelease,
                'time' => $time,
            ],
            'checks' => $checks,
            'optional_checks' => $optionalChecks,
            'time' => $time,
        ], $ready ? 200 : 503);
    }

    private function databaseIsReady(): bool
    {
        try {
            DB::select('SELECT 1');

            return true;
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function cacheIsReady(): bool
    {
        try {
            return Cache::remember(
                'health:cache-sentinel:v2',
                10,
                static fn (): string => 'ok'
            ) === 'ok';
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function criticalSchemaIsReady(): bool
    {
        try {
            return (bool) Cache::remember(
                $this->schemaCacheKey(
                    'critical',
                    self::CRITICAL_TABLES,
                    self::CRITICAL_COLUMNS
                ),
                60,
                fn (): bool => $this->scanCriticalSchema()
            );
        } catch (Throwable) {
            return $this->scanCriticalSchema();
        }
    }

    private function scanCriticalSchema(): bool
    {
        foreach (self::CRITICAL_TABLES as $table) {
            if (!$this->tableExists($table)) {
                return false;
            }
        }

        foreach (self::CRITICAL_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                try {
                    if (!Schema::hasColumn($table, $column)) {
                        return false;
                    }
                } catch (Throwable $exception) {
                    return false;
                }
            }
        }

        return true;
    }

    private function launchSchemaIsReady(): bool
    {
        try {
            return (bool) Cache::remember(
                $this->schemaCacheKey(
                    'launch',
                    self::LAUNCH_TABLES,
                    self::LAUNCH_COLUMNS
                ),
                60,
                fn (): bool => $this->scanLaunchSchema()
            );
        } catch (Throwable) {
            return $this->scanLaunchSchema();
        }
    }

    private function scanLaunchSchema(): bool
    {
        foreach (self::LAUNCH_TABLES as $table) {
            if (!$this->tableExists($table)) {
                return false;
            }
        }

        foreach (self::LAUNCH_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                try {
                    if (!Schema::hasColumn($table, $column)) {
                        return false;
                    }
                } catch (Throwable) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Schema readiness is cached in shared Redis. Derive the key from the
     * contract carried by this release so a candidate can never inherit a
     * positive result written by an older release with fewer requirements.
     *
     * @param list<string> $tables
     * @param array<string, list<string>> $columns
     */
    private function schemaCacheKey(string $scope, array $tables, array $columns): string
    {
        return sprintf(
            'health:%s-schema:%s',
            $scope,
            substr(hash('sha256', json_encode([$tables, $columns], JSON_THROW_ON_ERROR)), 0, 16)
        );
    }

}
