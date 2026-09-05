<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PaymentMethod;
use App\Models\WalletTransaction;
use App\Services\ArabicSearchNormalizer;
use App\Services\CertificateTextTemplateService;
use App\Services\SocialAuthProviderRegistry;
use App\Services\RecoveryEvidenceService;
use App\Services\AppReleasePolicyService;
use App\Support\PaymentEvidencePath;
use App\Support\StorageWriteOptions;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductionPreflight extends Command
{
    protected $signature = 'rokn:preflight
        {--connectivity : Verify the configured database and shared cache at runtime}
        {--configuration-only : Skip post-migration legacy-data release gates}
        {--allow-mixed-release : Permit old-shape rows only while the previous release still serves traffic}
        {--schema-only : Verify only the current release database contract}';

    protected $description = 'Fail a Rokn production deployment when required configuration is missing or unsafe';

    public function handle(): int
    {
        if ((bool) $this->option('schema-only')) {
            $failures = [
                ...$this->pendingMigrationFailures(),
                ...$this->requiredProductSchemaFailures(),
            ];
            if (!(bool) $this->option('allow-mixed-release')) {
                $failures = [...$failures, ...$this->releaseOverlapFailures()];
            }
        } else {
            $failures = $this->configurationFailures();

            if (!(bool) $this->option('configuration-only')) {
                $failures = [
                    ...$failures,
                    ...$this->pendingMigrationFailures(),
                    ...$this->requiredProductSchemaFailures(),
                    ...$this->mobileReleaseFailures(),
                    ...$this->legacyPublicAssetFailures(),
                    ...$this->developmentFixtureFailures(),
                    ...$this->publishedVideoFailures(),
                    ...$this->financialProvenanceFailures(),
                    ...$this->recoveryEvidenceFailures(),
                ];
                if (!(bool) $this->option('allow-mixed-release')) {
                    $failures = [...$failures, ...$this->releaseOverlapFailures()];
                }
            }
        }

        if ((bool) $this->option('connectivity')) {
            $failures = [...$failures, ...$this->connectivityFailures()];
        }

        if ($failures !== []) {
            $this->error('Rokn production preflight failed:');
            foreach ($failures as $failure) {
                $this->line(' - ' . $failure);
            }

            return self::FAILURE;
        }

        $this->info('Rokn production preflight passed.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function releaseOverlapFailures(): array
    {
        try {
            $failures = [];
            if (
                Schema::hasTable('saved_folders')
                && Schema::hasColumn('saved_folders', 'normalized_name')
            ) {
                $repairableFolders = DB::table('saved_folders')
                    ->whereNull('normalized_name')
                    ->count();
                if ($repairableFolders > 0) {
                    $failures[] = sprintf(
                        '%d saved folder(s) were written by the previous release; run rokn:release-finalize after old workers drain.',
                        $repairableFolders
                    );
                }
            }

            $staleCourseSearch = $this->staleCourseSearchCount();
            if ($staleCourseSearch > 0) {
                $failures[] = sprintf(
                    '%d course search row(s) were written by the previous release; run rokn:release-finalize after old workers drain.',
                    $staleCourseSearch
                );
            }

            return $failures;
        } catch (Throwable) {
            return ['Mixed-release backfill verification could not complete.'];
        }
    }

    private function staleCourseSearchCount(): int
    {
        if (
            !Schema::hasTable('courses')
            || !Schema::hasColumns('courses', [
                'name_ar', 'name_en', 'description_ar', 'description_en',
                'search_keywords_ar', 'search_keywords_en',
                'search_title_normalized', 'search_terms_normalized',
            ])
        ) {
            return 0;
        }

        $normalizer = app(ArabicSearchNormalizer::class);
        $stale = 0;
        foreach (
            DB::table('courses')
                ->select([
                    'id', 'name_ar', 'name_en', 'description_ar', 'description_en',
                    'search_keywords_ar', 'search_keywords_en',
                    'search_title_normalized', 'search_terms_normalized',
                ])
                ->lazyById(250) as $course
        ) {
            $title = implode(' ', array_filter([
                $course->name_ar,
                $course->name_en,
            ], static fn ($value): bool => trim((string) $value) !== ''));
            $terms = implode(' ', array_filter([
                $course->name_ar,
                $course->name_en,
                $course->description_ar,
                $course->description_en,
                $course->search_keywords_ar,
                $course->search_keywords_en,
            ], static fn ($value): bool => trim((string) $value) !== ''));
            if (
                !hash_equals(
                    (string) $course->search_title_normalized,
                    $normalizer->normalize($title)
                )
                || !hash_equals(
                    (string) $course->search_terms_normalized,
                    $normalizer->normalize($terms)
                )
            ) {
                $stale++;
            }
        }

        return $stale;
    }

    /** @return list<string> */
    private function pendingMigrationFailures(): array
    {
        try {
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles(database_path('migrations'));
            $ran = array_fill_keys($migrator->getRepository()->getRan(), true);
            $pending = array_values(array_filter(
                array_keys($files),
                static fn (string $migration): bool => !isset($ran[$migration])
            ));

            return $pending === []
                ? []
                : [sprintf(
                    'The release has %d pending database migration(s); traffic must not switch to this artifact.',
                    count($pending)
                )];
        } catch (Throwable) {
            return ['The migration ledger could not be verified; traffic must not switch to this artifact.'];
        }
    }

    /** @return list<string> */
    private function mobileReleaseFailures(): array
    {
        $readiness = app(AppReleasePolicyService::class)->launchReadiness();
        if ($readiness['ready']) {
            return [];
        }

        if ($readiness['required_channels'] === []) {
            return ['MOBILE_RELEASE_REQUIRED_CHANNELS must name at least one launch channel.'];
        }

        $failures = [];
        foreach ($readiness['required_channels'] as $channel) {
            $status = $readiness['channels'][$channel] ?? null;
            if (!(bool) ($status['ready'] ?? false)) {
                $failures[] = sprintf(
                    'Mobile release channel %s is not launch-ready (%s). Publish an active release with the correct build identity and official URL.',
                    $channel,
                    (string) ($status['reason'] ?? 'unknown'),
                );
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function requiredProductSchemaFailures(): array
    {
        $families = [
            'social sign-in' => ['api_tokens', 'social_accounts', 'social_oauth_attempts'],
            'course access and AI' => [
                'course_access_plans',
                'ai_entitlement_usages',
                'ai_usage_events',
                'course_chat_turns',
            ],
            'playback' => ['lesson_media_states', 'playback_sessions', 'lesson_watch_evidence'],
            'project delivery' => ['project_submissions', 'project_feedback_threads', 'project_feedback_messages'],
            'notifications' => [
                'student_notifications',
                'notification_campaigns',
                'notification_campaign_recipients',
                'notification_push_deliveries',
                'user_device_tokens',
            ],
            'wallet and rewards' => ['wallet_transactions', 'user_coin_task_attempts', 'reward_rules'],
            'WhatsApp linking' => ['user_whatsapp_connections', 'whatsapp_link_tokens'],
            'store billing' => ['store_purchases', 'store_notification_events'],
            'discounts' => ['coupon_redemptions'],
            'operations' => [
                'admin_audit_logs',
                'product_feature_flags',
                'operational_incidents',
                'bunny_storage_cleanup_candidates',
                'recovery_markers',
            ],
            'support feedback' => ['feedback_reports', 'feedback_attachments', 'support_case_messages', 'support_case_events'],
            'learning continuity' => [
                'student_section_progress',
                'watching_logs',
                'saved_folders',
                'saved_folder_lessons',
            ],
            'learner identity artifacts' => ['portfolio_items', 'certificates'],
            'resumable authoring' => [
                'bunny_direct_uploads',
                'course_authoring_revisions',
                'course_authoring_revision_entities',
                'admin_authoring_draft_receipts',
                'admin_authoring_create_intents',
                'profile_update_receipts',
            ],
        ];

        $failures = [];
        foreach ($families as $family => $tables) {
            $missing = array_values(array_filter(
                $tables,
                static fn (string $table): bool => !Schema::hasTable($table)
            ));
            if ($missing !== []) {
                $failures[] = sprintf(
                    'The %s schema is incomplete. Missing: %s. Run all forward migrations before release.',
                    $family,
                    implode(', ', $missing)
                );
            }
        }

        $requiredColumns = [
            'student_section_progress' => ['completed_at'],
            'watching_logs' => [
                'playback_session_id',
                'playback_session_started_at',
                'last_playback_sequence',
            ],
            'saved_folders' => ['normalized_name', 'client_request_id'],
            'feedback_reports' => [
                'client_request_id', 'request_fingerprint', 'guest_access_hash',
                'requester_email', 'order_id', 'version', 'first_response_due_at',
                'last_user_message_at', 'last_staff_message_at', 'closed_at',
                'reopened_at', 'retention_until', 'resolution_kind',
            ],
            'feedback_attachments' => ['support_case_message_id', 'sha256', 'scan_status'],
            'certificates' => [
                'public_id',
                'holder_name',
                'course_name',
                'certificate_text_template_key',
                'certificate_text',
                'status',
                'verification_level',
                'generation_lease_id',
                'revoked_at',
                'recovery_attempts',
                'recovery_next_attempt_at',
                'recovery_failed_at',
                'recovery_failure_code',
                'artifact_checked_at',
            ],
            'course_access_plans' => [
                'project_followup_message_limit',
                'project_followup_token_budget',
                'project_followup_budget_usd',
                'project_followup_reserve_usd',
            ],
            'course_enrollments' => [
                'user_id', 'course_id', 'order_id',
                'access_plan_id', 'access_plan_order_id', 'access_plan_snapshot',
                'enrolled_at', 'expires_at', 'is_active', 'access_granted_at',
                'completed_curriculum_revision', 'curriculum_completed_at',
            ],
            'ai_usage_events' => ['reservation_expires_at'],
            'wallet_transactions' => [
                'public_id', 'user_id', 'direction', 'category', 'bucket', 'amount',
                'paid_amount', 'reward_amount', 'balance_after',
                'paid_balance_after', 'reward_balance_after',
                'source_type', 'source_id', 'idempotency_key', 'metadata', 'occurred_at',
            ],
            'users' => [
                'wallet_coins', 'wallet_purchased_coins', 'wallet_reward_coins',
                'profile_revision',
            ],
            'settings' => ['ai_plan_policy', 'direct_checkout_discount_percent'],
            'orders' => [
                'user_id', 'course_id', 'access_plan_id', 'access_plan_snapshot',
                'parent_order_id', 'package_id', 'package_coins', 'course_code_id',
                'coupon_id', 'coupon_code', 'payment_method', 'payment_method_id',
                'payment_screenshot', 'order_ref', 'checkout_request_key',
                'wallet_transaction_id', 'checkout_expires_at', 'transaction_id',
                'payment_gateway_response', 'amount', 'discount_amount', 'final_amount',
                'gateway_gross_amount', 'gateway_fee_amount', 'gateway_net_amount',
                'gateway_currency', 'gateway_settlement_status', 'gateway_settled_at',
                'total_coins', 'paid_coins', 'reward_coins', 'status', 'financial_status',
                'notes', 'approved_at', 'reversed_at', 'reversal_reason',
                'recovered_coins', 'unrecovered_coins', 'approved_by', 'is_premium_user',
                'created_at', 'updated_at', 'deleted_at',
            ],
            'bills' => [
                'order_id', 'user_id', 'course_id', 'bill_number',
                'amount', 'tax_amount', 'total_amount', 'payment_status',
                'payment_method', 'due_date', 'paid_at', 'notes',
                'created_at', 'updated_at', 'deleted_at',
            ],
            'api_tokens' => [
                'session_id', 'device_id', 'platform', 'device_class', 'app_version',
                'app_build', 'auth_provider', 'auth_provider_user_id',
                'last_used_at', 'revoked_at',
            ],
            'user_device_tokens' => ['device_os', 'device_id'],
            'packages' => ['is_active', 'direct_enabled'],
            'courses' => [
                'authoring_version', 'authoring_request_id', 'deleted_at',
                'search_title_normalized', 'search_terms_normalized',
                'attachment_prompt_frequency', 'certificate_text_template_key',
            ],
            'course_authoring_revisions' => [
                'canonical_course_id', 'revision_course_id',
                'base_authoring_version', 'published_authoring_version',
                'status', 'active_slot', 'clone_key',
            ],
            'course_authoring_revision_entities' => [
                'course_authoring_revision_id', 'entity_type',
                'source_entity_id', 'revision_entity_id',
                'survives_publish', 'carries_learner_state',
                'learner_root_entity_id',
            ],
            'course_ratings' => ['version'],
            'social_oauth_attempts' => [
                'code_challenge', 'nonce_hash', 'completion_processing_at',
                'completion_claim_id', 'encrypted_completion_code',
                'encrypted_session_response',
            ],
            'portfolio_items' => ['client_request_id', 'request_fingerprint'],
            'portfolio_media' => [
                'client_request_id',
                'content_sha256',
                'mime_type',
                'size_bytes',
                'original_name',
            ],
            'notification_campaigns' => [
                'scheduled_at', 'selection_cursor', 'selection_finished_at',
                'resolved_count', 'skipped_count',
            ],
            'notification_campaign_recipients' => [
                'status', 'attempts', 'claimed_at', 'resolved_at',
            ],
            'notification_push_deliveries' => [
                'status', 'attempts', 'attempted_at', 'accepted_at',
                'failed_at', 'failure_code',
            ],
        ];
        foreach ($requiredColumns as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $missing = array_values(array_filter(
                $columns,
                static fn (string $column): bool => !Schema::hasColumn($table, $column)
            ));
            if ($missing !== []) {
                $failures[] = sprintf(
                    'The %s schema is stale. Missing columns: %s. Run all forward migrations before release.',
                    $table,
                    implode(', ', $missing)
                );
            }
        }
        if (Schema::hasTable('user_device_tokens')
            && Schema::hasColumn('user_device_tokens', 'device_id')
            && !Schema::hasIndex('user_device_tokens', ['device_id'], 'unique')) {
            $failures[] = 'Push installation ownership is not unique. Run the notification delivery migration before release.';
        }
        if (Schema::hasTable('certificates')
            && Schema::hasColumns('certificates', ['user_id', 'course_id'])
            && !Schema::hasIndex('certificates', ['user_id', 'course_id'], 'unique')) {
            $failures[] = 'Certificate issuance is not unique per learner and course. Run all certificate migrations before release.';
        }
        if (Schema::hasTable('certificates')
            && Schema::hasColumn('certificates', 'public_id')
            && !Schema::hasIndex('certificates', ['public_id'], 'unique')) {
            $failures[] = 'Certificate public verification IDs are not unique. Run all certificate migrations before release.';
        }

        return $failures;
    }

    /** @return list<string> */
    private function configurationFailures(): array
    {
        $failures = [];
        $require = static function (bool $condition, string $message) use (&$failures): void {
            if (!$condition) {
                $failures[] = $message;
            }
        };

        $appUrl = trim((string) config('app.url'));
        $appHost = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
        $appDomain = strtolower(trim((string) config('app.app_domain')));
        $publicWebUrl = rtrim(trim((string) config('public_links.base_url')), '/');
        $publicWebHost = strtolower((string) parse_url($publicWebUrl, PHP_URL_HOST));
        $appKey = trim((string) config('app.key'));
        $recoverySigningKey = trim((string) config('operations.recovery_evidence_signing_key'));
        $recoveryEvidenceDisk = trim((string) config('operations.recovery_evidence_disk'));
        $recoveryEvidenceDiskConfig = $recoveryEvidenceDisk !== ''
            ? config("filesystems.disks.{$recoveryEvidenceDisk}")
            : null;
        $backupEvidencePath = trim((string) config('operations.backup_evidence_path'));
        $restoreEvidencePath = trim((string) config('operations.recovery_evidence_path'));
        $absolutePath = static fn (string $path): bool => str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        $redisHost = strtolower(trim((string) config('database.redis.default.host')));
        $firebaseCredentials = trim((string) config('firebase.credentials.file'));
        $firebaseCredentialsBase64 = trim((string) config('firebase.credentials.base64'));
        $decodedFirebaseCredentials = $firebaseCredentialsBase64 !== ''
            ? base64_decode($firebaseCredentialsBase64, true)
            : false;
        $firebaseCredentialsJson = is_string($decodedFirebaseCredentials)
            ? $decodedFirebaseCredentials
            : (($firebaseCredentials !== '' && is_readable($firebaseCredentials))
                ? file_get_contents($firebaseCredentials)
                : false);
        $firebaseCredentialsData = is_string($firebaseCredentialsJson)
            ? json_decode($firebaseCredentialsJson, true)
            : null;
        $hasInjectedFirebaseCredentials = is_array($firebaseCredentialsData)
            && filled($firebaseCredentialsData['project_id'] ?? null)
            && filled($firebaseCredentialsData['client_email'] ?? null)
            && filled($firebaseCredentialsData['private_key'] ?? null);
        $trustedProxies = array_values(array_filter((array) config('trusted_proxies.proxies', [])));
        $usesDynamicTrustedEdge = $trustedProxies === ['*']
            && (bool) config('trusted_proxies.allow_dynamic_edge', false);
        $coursePdfDisk = trim((string) config('course_pdfs.disk'));
        $coursePdfDiskConfig = $coursePdfDisk !== '' ? config("filesystems.disks.{$coursePdfDisk}") : null;
        $coursePdfDriver = is_array($coursePdfDiskConfig) ? strtolower((string) ($coursePdfDiskConfig['driver'] ?? '')) : '';
        $publicDiskConfig = config('filesystems.disks.public');
        $privateObjectDiskConfig = config('filesystems.disks.s3');
        $publicDiskDriver = is_array($publicDiskConfig)
            ? strtolower((string) ($publicDiskConfig['driver'] ?? ''))
            : '';
        $publicBucket = is_array($publicDiskConfig)
            ? strtolower(trim((string) ($publicDiskConfig['bucket'] ?? '')))
            : '';
        $privateBucket = is_array($privateObjectDiskConfig)
            ? strtolower(trim((string) ($privateObjectDiskConfig['bucket'] ?? '')))
            : '';
        $publicStorageUrl = is_array($publicDiskConfig)
            ? rtrim(trim((string) ($publicDiskConfig['url'] ?? '')), '/')
            : '';
        $privateStorageUrl = is_array($privateObjectDiskConfig)
            ? rtrim(trim((string) ($privateObjectDiskConfig['url'] ?? '')), '/')
            : '';
        $publicStorageUrlParts = $publicStorageUrl !== ''
            ? parse_url($publicStorageUrl)
            : false;
        $publicStorageUrlValid = is_array($publicStorageUrlParts)
            && strtolower((string) ($publicStorageUrlParts['scheme'] ?? '')) === 'https'
            && filled($publicStorageUrlParts['host'] ?? null)
            && !isset($publicStorageUrlParts['user'])
            && !isset($publicStorageUrlParts['pass'])
            && !isset($publicStorageUrlParts['query'])
            && !isset($publicStorageUrlParts['fragment']);
        $feedbackDiskConfig = config('filesystems.disks.feedback');
        $paymentEvidenceDisk = trim((string) config('payment_evidence.disk'));
        $paymentEvidenceDiskConfig = $paymentEvidenceDisk !== ''
            ? config("filesystems.disks.{$paymentEvidenceDisk}")
            : null;
        $certificateTemplatePath = trim((string) config('certificate.template_path'));
        $certificateFontPath = trim((string) config('certificate.font_regular'));
        $certificateTemplateSize = $certificateTemplatePath !== ''
            && is_readable($certificateTemplatePath)
            ? @getimagesize($certificateTemplatePath)
            : false;
        $certificateTextTemplates = app(CertificateTextTemplateService::class);
        $certificateDefaultTextKey = trim((string) config('certificate.default_text_template_key'));
        $androidPackage = trim((string) config('app_links.android_package'));
        $androidFingerprints = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => strtoupper(trim((string) $value)),
            (array) config('app_links.android_sha256_fingerprints', [])
        ))));
        $appleAppIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) config('app_links.apple_app_ids', [])
        ))));
        $trustedHosts = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            (array) config('trusted_hosts.hosts', [])
        ))));
        $socialPublicApiUrl = trim((string) config('social_auth.public_api_url'));
        $socialPublicApiValid = $this->validSocialPublicApiUrl($socialPublicApiUrl);
        $socialPublicApiHost = strtolower((string) parse_url($socialPublicApiUrl, PHP_URL_HOST));
        $socialReturnUrls = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) config('social_auth.return_urls', [])
        ))));
        $socialProviders = app(SocialAuthProviderRegistry::class);
        $declaredSocialProviders = $socialProviders->declared();
        $launchChannels = array_values((array) config('mobile_contract.launch_channels', []));
        $requiresAndroidLinks = in_array(AppReleasePolicyService::CHANNEL_DIRECT, $launchChannels, true)
            || in_array(AppReleasePolicyService::CHANNEL_PLAY, $launchChannels, true);
        $requiresAppleLinks = in_array(AppReleasePolicyService::CHANNEL_APP_STORE, $launchChannels, true);
        $swaggerApiMiddleware = (array) config('l5-swagger.routes.middleware.api', []);
        $swaggerDocsMiddleware = (array) config('l5-swagger.routes.middleware.docs', []);

        $require(config('app.env') === 'production', 'APP_ENV must be production.');
        $require(config('app.debug') === false, 'APP_DEBUG must be false.');
        $require(
            config('l5-swagger.generate_always') === false,
            'L5_SWAGGER_GENERATE_ALWAYS must be false in production.'
        );
        $require(
            in_array('admin.only', $swaggerApiMiddleware, true)
                && in_array('admin.mfa', $swaggerApiMiddleware, true)
                && in_array('admin.only', $swaggerDocsMiddleware, true)
                && in_array('admin.mfa', $swaggerDocsMiddleware, true),
            'Swagger UI and generated contracts must require an administrator with MFA.'
        );
        $require(
            config('demo.seed_enabled') === false,
            'ROKN_SEED_DEMO must be false in production.'
        );
        $require($appKey !== '' && !str_contains($appKey, 'AAAAAAA'), 'APP_KEY must be a real, non-placeholder key.');
        $require(
            trim((string) config('operations.recovery_encryption_key_id')) !== '',
            'RECOVERY_ENCRYPTION_KEY_ID must identify the APP_KEY generation used by encrypted production data.'
        );
        $require(
            strlen($recoverySigningKey) >= 32,
            'RECOVERY_EVIDENCE_SIGNING_KEY must be a separate stable secret of at least 32 characters.'
        );
        $require(
            $recoverySigningKey === '' || !hash_equals($appKey, $recoverySigningKey),
            'RECOVERY_EVIDENCE_SIGNING_KEY must not reuse APP_KEY.'
        );
        $recoveryEvidenceDriver = is_array($recoveryEvidenceDiskConfig)
            ? strtolower((string) ($recoveryEvidenceDiskConfig['driver'] ?? ''))
            : '';
        $durableEvidenceDisk = is_array($recoveryEvidenceDiskConfig)
            && $recoveryEvidenceDriver !== 'local'
            && (
                $recoveryEvidenceDriver === 's3'
                || ($recoveryEvidenceDiskConfig['visibility'] ?? null) !== 'public'
            );
        $durableEvidencePaths = $backupEvidencePath !== ''
            && $restoreEvidencePath !== ''
            && $backupEvidencePath !== $restoreEvidencePath
            && (
                $durableEvidenceDisk
                || ($recoveryEvidenceDisk === ''
                    && $absolutePath($backupEvidencePath)
                    && $absolutePath($restoreEvidencePath))
            );
        $require(
            $durableEvidencePaths,
            'Recovery evidence must use a configured private shared disk with distinct object paths, or distinct absolute durable mounted paths.'
        );
        $require((int) config('operations.recovery_rpo_minutes') > 0, 'RECOVERY_RPO_MINUTES must be positive.');
        $require((int) config('operations.recovery_rto_minutes') > 0, 'RECOVERY_RTO_MINUTES must be positive.');
        $require(
            str_starts_with($appUrl, 'https://')
                && $appHost !== ''
                && !in_array($appHost, ['localhost', 'example.com', 'api.example.com'], true),
            'APP_URL must be the real public HTTPS API origin.'
        );
        $require(
            $appDomain !== '' && !in_array($appDomain, ['localhost', 'example.com'], true),
            'APP_DOMAIN must be the real public application domain.'
        );
        $require(
            str_starts_with($publicWebUrl, 'https://')
                && $publicWebHost === $appDomain
                && in_array($publicWebHost, $trustedHosts, true),
            'PUBLIC_WEB_URL must be the trusted branded HTTPS APP_DOMAIN used by QR and app links.'
        );
        $require(config('app.timezone') === 'UTC', 'APP_TIMEZONE must be UTC.');
        $require(config('app.business_timezone') === 'Africa/Cairo', 'BUSINESS_TIMEZONE must be Africa/Cairo.');
        $databaseTimezone = (string) config('database.connections.' . config('database.default') . '.timezone');
        $require(
            in_array($databaseTimezone, ['+00:00', 'UTC'], true),
            'DB_TIMEZONE must be UTC (+00:00).'
        );
        $require(
            $trustedHosts !== []
                && collect($trustedHosts)->every(fn (string $host): bool => $this->validPublicHost($host))
                && in_array($appHost, $trustedHosts, true),
            'APP_TRUSTED_HOSTS must contain explicit non-local public hosts, including the APP_URL host.'
        );
        $require(
            !$requiresAndroidLinks || $this->validAndroidPackage($androidPackage),
            'APP_LINK_ANDROID_PACKAGE must be the real Android application ID while an Android release channel is enabled.'
        );
        $require(
            !$requiresAndroidLinks || ($androidFingerprints !== []
                && collect($androidFingerprints)->every(fn (string $value): bool => $this->validAndroidFingerprint($value))),
            'APP_LINK_ANDROID_SHA256_FINGERPRINTS must contain valid colon-separated SHA-256 signing fingerprints while an Android release channel is enabled.'
        );
        $require(
            !($requiresAppleLinks || $declaredSocialProviders->contains('apple'))
                || ($appleAppIds !== []
                    && collect($appleAppIds)->every(fn (string $value): bool => $this->validAppleAppId($value))),
            'APP_LINK_APPLE_APP_IDS must contain valid Team-ID and bundle-ID pairs while Apple sign-in or the App Store channel is enabled.'
        );
        $require(
            $socialPublicApiValid,
            'SOCIAL_AUTH_PUBLIC_API_URL must be the real public HTTPS API prefix ending in /api/v1.'
        );
        $require(
            !$socialPublicApiValid || in_array($socialPublicApiHost, $trustedHosts, true),
            'APP_TRUSTED_HOSTS must include the SOCIAL_AUTH_PUBLIC_API_URL host.'
        );
        $require(
            $socialReturnUrls !== []
                && collect($socialReturnUrls)->every(fn (string $url): bool => $this->validSocialReturnUrl($url)),
            'SOCIAL_AUTH_RETURN_URLS must contain only the explicit rokn://auth callback.'
        );

        $require(in_array(config('database.default'), ['mysql', 'pgsql'], true), 'Production DB_CONNECTION must be mysql or pgsql.');
        $require(config('cache.default') === 'redis', 'CACHE_DRIVER must be redis.');
        $require(config('queue.default') === 'redis', 'QUEUE_CONNECTION must be redis.');
        $longestJobTimeout = max(1, (int) config('queue.longest_job_timeout_seconds', 300));
        $retryHeadroom = max(1, (int) config('queue.retry_headroom_seconds', 30));
        $require(
            (int) config('queue.connections.redis.retry_after') >= $longestJobTimeout + $retryHeadroom,
            'REDIS_QUEUE_RETRY_AFTER must exceed the configured longest job timeout with recovery headroom.'
        );
        $require(
            config('queue.failed.driver') === 'database',
            'QUEUE_FAILED_DRIVER must persist dead-letter jobs in the database.'
        );
        $queueChannels = [
            (string) config('queue.connections.redis.queue', 'default'),
            (string) config('queue.channels.notifications'),
            (string) config('queue.channels.ai_chat'),
            (string) config('queue.channels.ai_feedback'),
            (string) config('queue.channels.media'),
            (string) config('queue.channels.operations'),
            (string) config('webhooks.queue'),
        ];
        $require(
            count(array_unique(array_filter($queueChannels))) === 7,
            'default, notifications, ai-chat, ai-feedback, media, operations and webhooks must use isolated queue names.'
        );
        $monitoredQueueChannels = array_values(array_unique(array_filter(array_map(
            static fn ($queue): string => trim((string) $queue),
            (array) config('operations.queue_heartbeat_required_queues', [])
        ))));
        $expectedQueueChannels = array_values(array_unique(array_filter($queueChannels)));
        sort($monitoredQueueChannels);
        sort($expectedQueueChannels);
        $require(
            $monitoredQueueChannels === $expectedQueueChannels,
            'QUEUE_HEARTBEAT_REQUIRED_QUEUES must match the isolated production queues.'
        );
        $require(config('session.driver') === 'redis', 'SESSION_DRIVER must be redis.');
        $require(config('session.secure') === true, 'SESSION_SECURE_COOKIE must be true.');
        $require(
            $usesDynamicTrustedEdge || (
                $trustedProxies !== []
                && collect($trustedProxies)->every(fn ($proxy) => $this->validTrustedProxy((string) $proxy))
            ),
            'TRUSTED_PROXIES must contain explicit edge IPs/CIDRs, or use * with TRUSTED_PROXIES_ALLOW_DYNAMIC_EDGE=true only on a managed private origin.'
        );
        $require(
            $redisHost !== '' && !in_array($redisHost, ['127.0.0.1', 'localhost'], true),
            'REDIS_HOST must point to the shared production Redis service.'
        );

        $require($this->configured('bunny.stream_api_key'), 'BUNNY_STREAM_API_KEY is required.');
        $require($this->configured('bunny.library_id'), 'BUNNY_STREAM_LIBRARY_ID is required.');
        $require($this->configured('bunny.cdn_hostname'), 'BUNNY_CDN_HOSTNAME is required.');
        $require(
            $this->validBareHostname((string) config('bunny.cdn_hostname')),
            'BUNNY_CDN_HOSTNAME must be a bare HTTPS hostname without a scheme, port, path, or credentials.'
        );
        $require($this->configured('bunny.token_auth_key'), 'BUNNY_TOKEN_AUTH_KEY is required for signed playback.');
        $require($this->configured('bunny.storage_zone'), 'BUNNY_STORAGE_ZONE is required for portfolio and thumbnail uploads.');
        $require($this->configured('bunny.storage_password'), 'BUNNY_STORAGE_PASSWORD is required for portfolio and thumbnail uploads.');
        $require($this->configured('bunny.storage_cdn_hostname'), 'BUNNY_STORAGE_CDN_HOSTNAME is required for asset delivery.');
        $require(
            $this->validBareHostname((string) config('bunny.storage_cdn_hostname')),
            'BUNNY_STORAGE_CDN_HOSTNAME must be a bare HTTPS hostname without a scheme, port, path, or credentials.'
        );
        $require($this->configured('bunny.storage_token_auth_key'), 'BUNNY_STORAGE_TOKEN_AUTH_KEY is required for signed asset delivery.');
        $playbackTtl = (int) config('playback.signed_url_ttl_seconds');
        $refreshMargin = (int) config('playback.manifest_refresh_margin_seconds');
        $require(
            $playbackTtl >= 600 && $playbackTtl <= 7200,
            'PLAYBACK_SIGNED_URL_TTL_SECONDS must be between 600 and 7200.'
        );
        $require(
            $refreshMargin >= 60 && $refreshMargin < $playbackTtl,
            'PLAYBACK_REFRESH_MARGIN_SECONDS must be at least 60 and less than the signed playback URL TTL.'
        );

        $require(config('kashier.mode') === 'live', 'KASHIER_MODE must be live.');
        $require($this->configured('kashier.live.api_key'), 'KASHIER_LIVE_API_KEY is required.');
        $require($this->configured('kashier.live.secret_key'), 'KASHIER_LIVE_SECRET_KEY is required.');
        $require($this->configured('kashier.live.mid'), 'KASHIER_LIVE_MID is required.');

        if ((bool) config('whatsapp.enabled')) {
            $whatsAppApiUrl = trim((string) config('whatsapp.whatspie.api_url'));
            $whatsAppApiParts = $whatsAppApiUrl !== '' ? parse_url($whatsAppApiUrl) : false;
            $whatsAppBotPhone = trim((string) config('whatsapp.linking.bot_phone'));
            $whatsAppWebhookSecret = trim((string) config('whatsapp.linking.webhook_secret'));
            $require(
                is_array($whatsAppApiParts)
                    && strtolower((string) ($whatsAppApiParts['scheme'] ?? '')) === 'https'
                    && filled($whatsAppApiParts['host'] ?? null)
                    && !isset($whatsAppApiParts['user'])
                    && !isset($whatsAppApiParts['pass']),
                'WHATSPIE_API_URL must be a real HTTPS provider endpoint.'
            );
            $require($this->configured('whatsapp.whatspie.api_key'), 'WHATSPIE_API_KEY is required for WhatsApp replies.');
            $require($this->configured('whatsapp.whatspie.device'), 'WHATSPIE_DEVICE is required for WhatsApp replies.');
            $require(
                preg_match('/\A[1-9][0-9]{7,14}\z/D', $whatsAppBotPhone) === 1,
                'WHATSAPP_BOT_PHONE must contain 8 to 15 international digits without a leading plus.'
            );
            $require(
                strlen($whatsAppWebhookSecret) >= 32,
                'WHATSAPP_WEBHOOK_SECRET must be a stable high-entropy secret of at least 32 characters.'
            );
        }

        $require($declaredSocialProviders->isNotEmpty(), 'SOCIAL_AUTH_PROVIDERS must declare at least one sign-in provider.');
        foreach ($declaredSocialProviders as $provider) {
            $require(
                $socialProviders->isReady($provider),
                'Declared social provider '.$provider.' is not ready: '.$socialProviders->reason($provider)
            );
        }

        $require($this->configured('openrouter.api_key'), 'OPENROUTER_API_KEY is required while Rokn AI is enabled.');
        $require($this->configured('openrouter.default_model'), 'OPENROUTER_DEFAULT_MODEL is required while Rokn AI is enabled.');
        $allowedOpenRouterModels = array_values(array_filter(
            (array) config('openrouter.allowed_models', [])
        ));
        $require(
            in_array(
                (string) config('openrouter.default_model'),
                $allowedOpenRouterModels,
                true
            ),
            'OPENROUTER_DEFAULT_MODEL must be present in OPENROUTER_ALLOWED_MODELS.'
        );
        $require(
            in_array(
                (string) config('openrouter.project_model'),
                $allowedOpenRouterModels,
                true
            ),
            'OPENROUTER_PROJECT_MODEL must be present in OPENROUTER_ALLOWED_MODELS.'
        );
        foreach ((array) config('openrouter.fallback_models', []) as $fallbackModel) {
            $require(
                is_string($fallbackModel)
                    && in_array($fallbackModel, $allowedOpenRouterModels, true),
                'Every OPENROUTER_FALLBACK_MODELS entry must be present in OPENROUTER_ALLOWED_MODELS.'
            );
        }
        $require(
            in_array(
                (string) config('openrouter.provider_sort'),
                ['latency', 'throughput', 'price'],
                true
            ),
            'OPENROUTER_PROVIDER_SORT must be latency, throughput, or price.'
        );
        $require(
            in_array(
                (string) config('openrouter.provider_data_collection'),
                ['allow', 'deny'],
                true
            ),
            'OPENROUTER_PROVIDER_DATA_COLLECTION must be allow or deny.'
        );
        if ((bool) config('openrouter.web_search_enabled')) {
            $require(
                (int) config('openrouter.web_search_max_results') >= 1
                    && (int) config('openrouter.web_search_max_results') <= 5,
                'OPENROUTER_WEB_SEARCH_MAX_RESULTS must be between 1 and 5.'
            );
            $require(
                (int) config('openrouter.web_search_max_total_results') >= 1
                    && (int) config('openrouter.web_search_max_total_results') <= 8,
                'OPENROUTER_WEB_SEARCH_MAX_TOTAL_RESULTS must be between 1 and 8.'
            );
        }
        $require((int) config('openrouter.global_daily_request_limit') > 0, 'OpenRouter daily request budget must be positive.');
        $require((int) config('openrouter.global_daily_token_budget') > 0, 'OpenRouter daily token budget must be positive.');
        $require((int) config('openrouter.global_monthly_token_budget') > 0, 'OpenRouter monthly token budget must be positive.');
        $require(
            config('course_plans.economics_configured') === true,
            'ROKN_NET_USD_PER_PAID_COIN and ROKN_AI_COST_SAFETY_MULTIPLIER must be explicitly configured.'
        );
        $require(
            (float) config('course_plans.net_usd_per_paid_coin') > 0,
            'ROKN_NET_USD_PER_PAID_COIN must be a positive finance-calibrated value.'
        );
        $require(
            (float) config('course_plans.ai_cost_safety_multiplier') >= 1,
            'ROKN_AI_COST_SAFETY_MULTIPLIER must be at least 1.'
        );
        $require(
            (int) config('course_plans.ai_reservation_ttl_seconds')
                >= max(60, (int) config('openrouter.timeout_seconds') + 15),
            'ROKN_AI_RESERVATION_TTL_SECONDS must exceed the provider timeout with recovery headroom.'
        );

        $require(
            !in_array(config('projects.submission_disk'), ['local', 'public', null, ''], true),
            'PROJECT_SUBMISSION_DISK must be a private shared disk.'
        );
        $require(
            !in_array(config('certificate.disk'), ['local', 'public', null, ''], true),
            'CERTIFICATE_DISK must be a shared disk.'
        );
        $require(
            is_array($certificateTemplateSize)
                && (int) ($certificateTemplateSize[0] ?? 0) === 1200
                && (int) ($certificateTemplateSize[1] ?? 0) === 900,
            'CERTIFICATE_TEMPLATE_PATH must be a readable 1200x900 certificate identity image.'
        );
        $require(
            $certificateFontPath !== '' && is_readable($certificateFontPath),
            'CERTIFICATE_FONT_PATH must be a readable Arabic-capable font file.'
        );
        $require(
            $certificateTextTemplates->catalogue() !== []
                && $certificateTextTemplates->resolve($certificateDefaultTextKey) !== null,
            'Certificate text templates must contain a complete approved default template.'
        );
        $require(
            is_array($publicDiskConfig)
                && (
                    ($publicDiskConfig['driver'] ?? null) !== 'local'
                    || rtrim((string) ($publicDiskConfig['root'] ?? ''), '/\\')
                        !== rtrim(storage_path('app/public'), '/\\')
                ),
            'Course and instructor images require SHARED_PUBLIC_STORAGE_PATH on durable shared storage.'
        );
        $require(
            $publicDiskDriver !== 's3'
                || (
                    $publicBucket !== ''
                    && $publicStorageUrlValid
                    && filled($publicDiskConfig['key'] ?? null)
                    && filled($publicDiskConfig['secret'] ?? null)
                    && filled($publicDiskConfig['region'] ?? null)
                    && filled($publicDiskConfig['endpoint'] ?? null)
                ),
            'The public S3/R2 disk requires complete PUBLIC_AWS_* credentials, endpoint, bucket and a clean public HTTPS URL.'
        );
        $require(
            $publicDiskDriver !== 's3'
                || $privateBucket === ''
                || !hash_equals($privateBucket, $publicBucket),
            'PUBLIC_AWS_BUCKET must be separate from the private AWS_BUCKET.'
        );
        $require(
            $publicDiskDriver !== 's3'
                || $privateStorageUrl === ''
                || !hash_equals(strtolower($privateStorageUrl), strtolower($publicStorageUrl)),
            'PUBLIC_AWS_URL must not reuse the private AWS_URL.'
        );
        $require(
            !in_array($coursePdfDisk, ['', 'local', 'public'], true) && is_array($coursePdfDiskConfig),
            'COURSE_PDF_DISK must name a configured private shared disk.'
        );
        $require(
            !is_array($coursePdfDiskConfig)
                || $coursePdfDriver === 's3'
                || ($coursePdfDiskConfig['visibility'] ?? null) !== 'public',
            'COURSE_PDF_DISK must not have public visibility.'
        );
        $require(
            !is_array($coursePdfDiskConfig)
                || $coursePdfDriver !== 'local'
                || config('course_pdfs.shared_storage') === true,
            'A local-driver COURSE_PDF_DISK requires COURSE_PDF_SHARED_STORAGE=true and a shared mounted path.'
        );
        $feedbackDriver = strtolower((string) ($feedbackDiskConfig['driver'] ?? ''));
        $require(
            is_array($feedbackDiskConfig)
                && (
                    $feedbackDriver === 's3'
                    || ($feedbackDiskConfig['visibility'] ?? null) === 'private'
                )
                && (
                    $feedbackDriver !== 'local'
                    || (($feedbackDiskConfig['shared'] ?? false) === true
                        && trim((string) ($feedbackDiskConfig['root'] ?? '')) !== '')
                ),
            'The feedback disk must use private durable object storage or an explicitly shared mounted path.'
        );
        $require(
            $paymentEvidenceDisk === 's3'
                && is_array($paymentEvidenceDiskConfig)
                && strtolower((string) ($paymentEvidenceDiskConfig['driver'] ?? '')) === 's3'
                && ($paymentEvidenceDiskConfig['visibility'] ?? null) !== 'public',
            'PAYMENT_EVIDENCE_DISK must be the private shared s3 disk in production.'
        );

        $require(
            filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false,
            'MAIL_FROM_ADDRESS must be a real support email address.'
        );
        $require(
            $hasInjectedFirebaseCredentials,
            'Firebase credentials must be a valid FIREBASE_CREDENTIALS_BASE64 secret or a readable FIREBASE_CREDENTIALS file.'
        );

        return $failures;
    }

    /** @return list<string> */
    private function recoveryEvidenceFailures(): array
    {
        try {
            $state = app(RecoveryEvidenceService::class)->readiness();
            $failures = [];
            if ((bool) ($state['recovery_mode'] ?? false)) {
                $failures[] = 'DISASTER_RECOVERY_MODE is active; traffic and purchases must remain blocked.';
            }
            $failed = array_keys(array_filter(
                (array) ($state['checks'] ?? []),
                static fn ($passed): bool => $passed !== true
            ));
            if ($failed !== []) {
                $failures[] = 'Recovery evidence is incomplete: '.implode(', ', $failed).'.';
            }

            return $failures;
        } catch (Throwable) {
            return ['Signed backup and restore evidence could not be verified.'];
        }
    }

    /** @return list<string> */
    private function developmentFixtureFailures(): array
    {
        try {
            $markers = [];
            if (Schema::hasTable('courses') && Schema::hasColumn('courses', 'name_en')) {
                $markers['demo course'] = DB::table('courses')
                    ->where('name_en', 'Rokn 30-Reel Demo Course')
                    ->exists();
            }
            if (Schema::hasTable('packages') && Schema::hasColumn('packages', 'name_en')) {
                $markers['checkout test package'] = DB::table('packages')
                    ->where('name_en', 'Checkout Test')
                    ->exists();
            }
            if (Schema::hasTable('course_codes') && Schema::hasColumn('course_codes', 'code')) {
                $markers['sample course access codes'] = DB::table('course_codes')
                    ->whereIn('code', ['COURSE123', 'LESSON456', 'MULTI789', 'EXPIRED', 'FUTURE'])
                    ->exists();
            }

            $found = array_keys(array_filter($markers));

            return $found === []
                ? []
                : ['Development fixtures remain in production data: '.implode(', ', $found).'. Remove them before release.'];
        } catch (Throwable) {
            return ['Development-fixture audit could not complete; production release is blocked.'];
        }
    }

    /** @return list<string> */
    private function legacyPublicAssetFailures(): array
    {
        $failures = [];

        try {
            if (Schema::hasTable('users') && Schema::hasColumn('users', 'profile_image')) {
                $svgCount = DB::table('users')
                    ->whereNotNull('profile_image')
                    ->whereRaw('LOWER(profile_image) LIKE ?', ['%.svg'])
                    ->count();
                if ($svgCount > 0) {
                    $failures[] = "{$svgCount} public SVG profile image(s) remain. Run security:quarantine-profile-svg --execute and audit again.";
                }
            }

            if (Schema::hasTable('course_pdfs')) {
                $targetDisk = trim((string) config('course_pdfs.disk'));
                if (!Schema::hasColumn('course_pdfs', 'storage_disk')) {
                    $legacyCount = DB::table('course_pdfs')->count();
                } else {
                    $legacyCount = DB::table('course_pdfs')
                        ->where(function ($query) use ($targetDisk): void {
                            $query->whereNull('storage_disk')->orWhere('storage_disk', '<>', $targetDisk);
                        })
                        ->count();
                }
                if ($legacyCount > 0) {
                    $failures[] = "{$legacyCount} course PDF(s) are not on the configured shared disk. Run course-pdfs:migrate-storage --execute, verify, then repeat with --delete-source.";
                }
            }

            if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'payment_screenshot')) {
                $legacyPaymentEvidence = 0;
                $invalidPaymentEvidence = 0;
                DB::table('orders')
                    ->select(['id', 'payment_screenshot'])
                    ->whereNotNull('payment_screenshot')
                    ->where('payment_screenshot', '<>', '')
                    ->orderBy('id')
                    ->chunkById(500, function ($orders) use (
                        &$legacyPaymentEvidence,
                        &$invalidPaymentEvidence
                    ): void {
                        foreach ($orders as $order) {
                            if (PaymentEvidencePath::isLegacyPublicReference($order->payment_screenshot)) {
                                $legacyPaymentEvidence++;
                            } elseif (PaymentEvidencePath::from($order->payment_screenshot) === null) {
                                $invalidPaymentEvidence++;
                            }
                        }
                    });
                if ($legacyPaymentEvidence > 0) {
                    $failures[] = "{$legacyPaymentEvidence} legacy public payment evidence record(s) remain. Move each object to PAYMENT_EVIDENCE_DISK under payment-evidence/, remove its public copy, then store only the private relative path.";
                }
                if ($invalidPaymentEvidence > 0) {
                    $failures[] = "{$invalidPaymentEvidence} invalid payment evidence path(s) remain. Store only normalized private keys under payment-evidence/.";
                }
            }

            foreach ([
                ['table' => 'portfolio_media', 'column' => 'file_path', 'label' => 'portfolio image'],
                ['table' => 'lessons', 'column' => 'thumbnail_path', 'label' => 'lesson thumbnail'],
            ] as $asset) {
                if (!Schema::hasTable($asset['table']) || !Schema::hasColumn($asset['table'], $asset['column'])) {
                    continue;
                }

                $duplicates = DB::table($asset['table'])
                    ->select($asset['column'])
                    ->whereNotNull($asset['column'])
                    ->where($asset['column'], '<>', '')
                    ->groupBy($asset['column'])
                    ->havingRaw('COUNT(*) > 1')
                    ->get()
                    ->count();
                if ($duplicates > 0) {
                    $failures[] = "{$duplicates} duplicate Bunny {$asset['label']} object key(s) remain. Re-upload each affected record with a unique key before release.";
                }
            }
        } catch (Throwable) {
            $failures[] = 'Legacy public-asset audit could not complete; production release is blocked.';
        }

        return $failures;
    }

    /** @return list<string> */
    private function connectivityFailures(): array
    {
        $failures = [];

        $publicOrigin = rtrim(trim((string) config('public_links.base_url')), '/');
        $releasePolicy = app(AppReleasePolicyService::class);
        $releaseChannels = $releasePolicy->launchReadiness()['channels'] ?? [];
        $androidAssociationRequired = collect([
            AppReleasePolicyService::CHANNEL_PLAY,
            AppReleasePolicyService::CHANNEL_DIRECT,
        ])->contains(fn (string $channel): bool => (bool) ($releaseChannels[$channel]['ready'] ?? false));

        // A public association is a release contract, not a prerequisite for
        // deploying an API-only revision while no Android release is active.
        // Once either Android channel is active, keep the strict host check.
        if ($androidAssociationRequired) {
            $expectedIdentity = $releasePolicy->publicContractIdentity();
            try {
                $association = Http::acceptJson()
                    ->connectTimeout(3)
                    ->timeout(6)
                    ->withoutRedirecting()
                    ->get($publicOrigin.'/.well-known/assetlinks.json');
                if ($association->redirect()) {
                    $redirect = trim((string) $association->header('Location'));
                    $expectedHost = strtolower((string) parse_url($publicOrigin, PHP_URL_HOST));
                    $redirectUrl = str_starts_with($redirect, '/') ? $publicOrigin.$redirect : $redirect;
                    $redirectHost = strtolower((string) parse_url($redirectUrl, PHP_URL_HOST));
                    if ($redirect === '' || $redirectHost !== $expectedHost) {
                        $failures[] = 'The branded app-link association redirects to a different deployment host.';
                    } else {
                        $association = Http::acceptJson()
                            ->connectTimeout(3)
                            ->timeout(6)
                            ->withoutRedirecting()
                            ->get($redirectUrl);
                        if (!$this->validAndroidAssociation($association, $expectedIdentity)) {
                            $failures[] = 'The branded app-link redirect does not terminate on the configured Android contract.';
                        }
                    }
                } elseif (!$this->validAndroidAssociation($association, $expectedIdentity)) {
                    $failures[] = 'The branded public host does not serve the configured Android app-link contract.';
                }
            } catch (Throwable) {
                $failures[] = 'The branded public app-link host is unreachable or did not return a stable contract identity.';
            }
        }

        try {
            DB::select('SELECT 1');
            $driver = DB::connection()->getDriverName();
            $databaseTimezone = match ($driver) {
                'mysql' => (string) (DB::selectOne('SELECT @@session.time_zone AS timezone')->timezone ?? ''),
                'pgsql' => (string) (DB::selectOne("SELECT current_setting('TIMEZONE') AS timezone")->timezone ?? ''),
                default => '',
            };
            if (!in_array(strtoupper($databaseTimezone), ['+00:00', 'UTC', 'ETC/UTC'], true)) {
                $failures[] = 'The live database session timezone is not UTC.';
            }
        } catch (Throwable) {
            $failures[] = 'The configured production database is not reachable.';
        }

        $privateDisks = array_values(array_unique(array_filter(array_map(
            static fn ($disk): string => trim((string) $disk),
            [
                config('course_pdfs.disk'),
                config('projects.submission_disk'),
                config('certificate.disk'),
                config('operations.recovery_evidence_disk'),
                config('payment_evidence.disk'),
            ]
        ))));
        foreach ($privateDisks as $diskName) {
            if (!is_array(config("filesystems.disks.{$diskName}"))) continue;
            $failure = $this->privateStorageProbeFailure(
                $diskName,
                "The configured private shared disk {$diskName} is not reachable."
            );
            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        $feedbackFailure = $this->privateStorageProbeFailure(
            'feedback',
            'The shared private feedback disk is not reachable.'
        );
        if ($feedbackFailure !== null) {
            $failures[] = $feedbackFailure;
        }

        $publicProbe = 'preflight/' . bin2hex(random_bytes(8)) . '.txt';
        $publicToken = 'rokn-public-storage-' . bin2hex(random_bytes(12));
        try {
            $public = Storage::disk('public');
            $public->put(
                $publicProbe,
                $publicToken,
                StorageWriteOptions::forDisk('public', 'public')
            );
            $url = $public->url($publicProbe);
            $response = Http::connectTimeout(3)->timeout(8)->get($url);
            if (!$response->successful() || !hash_equals($publicToken, $response->body())) {
                $failures[] = 'The durable public-image disk writes objects but does not deliver them through its public URL.';
            }
        } catch (Throwable) {
            $failures[] = 'The durable public-image disk is not writable and publicly reachable.';
        } finally {
            try {
                Storage::disk('public')->delete($publicProbe);
            } catch (Throwable) {
                // The reachability failure above is the useful signal.
            }
        }

        $key = 'preflight:' . bin2hex(random_bytes(8));
        try {
            Cache::put($key, 'ok', 15);
            if (Cache::get($key) !== 'ok') {
                $failures[] = 'The shared cache did not return its preflight value.';
            }
        } catch (Throwable) {
            $failures[] = 'The configured shared cache is not reachable.';
        } finally {
            try {
                Cache::forget($key);
            } catch (Throwable) {
                // The reachability failure above is the useful operator signal.
            }
        }

        return $failures;
    }

    private function privateStorageProbeFailure(string $diskName, string $message): ?string
    {
        $lastFailure = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $probe = 'preflight/' . bin2hex(random_bytes(8)) . '.txt';
            try {
                $disk = Storage::disk($diskName);
                $written = $disk->put(
                    $probe,
                    'ok',
                    StorageWriteOptions::forDisk($diskName, 'private')
                );
                if ($written !== false && $disk->exists($probe) && $disk->get($probe) === 'ok') {
                    return null;
                }
                $lastFailure = 'verification_failed';
            } catch (Throwable $exception) {
                $detail = preg_replace(
                    '/https?:\/\/\S+/i',
                    '[redacted-url]',
                    str_replace(["\r", "\n"], ' ', $exception->getMessage())
                );
                $lastFailure = class_basename($exception) . ': ' . substr((string) $detail, 0, 180);
                Storage::forgetDisk($diskName);
            } finally {
                try {
                    Storage::disk($diskName)->delete($probe);
                } catch (Throwable) {
                    // The probe result above is the useful signal.
                }
            }

            if ($attempt < 3) {
                usleep($attempt * 200_000);
            }
        }

        return $message . ' [' . ($lastFailure ?? 'unknown') . ']';
    }

    /** @return list<string> */
    private function publishedVideoFailures(): array
    {
        if (!Schema::hasTable('courses')
            || !Schema::hasTable('course_sections')
            || !Schema::hasTable('lessons')
            || !Schema::hasColumn('courses', 'is_coming_soon')
            || !Schema::hasColumn('lessons', 'video_source_type')
            || !Schema::hasColumn('lessons', 'bunny_video_id')) {
            return ['Published-video audit cannot run until the current content schema is migrated.'];
        }

        try {
            $invalid = DB::table('course_sections as sections')
                ->join('courses', 'courses.id', '=', 'sections.course_id')
                ->join('lessons', function ($join): void {
                    $join->on('lessons.id', '=', 'sections.sectionable_id')
                        ->where('sections.sectionable_type', '=', \App\Models\Lesson::class);
                })
                ->where('courses.is_coming_soon', false)
                ->whereNull('sections.deleted_at')
                ->where(function ($query): void {
                    $query->whereNull('lessons.bunny_video_id')
                        ->orWhere('lessons.bunny_video_id', '')
                        ->orWhere('lessons.video_source_type', '<>', 'bunny')
                        ->orWhereNull('lessons.video_source_type');
                })
                ->count();

            return $invalid > 0
                ? ["{$invalid} published lesson(s) are not backed by a Bunny Stream GUID; migrate them or return their courses to coming-soon before release."]
                : [];
        } catch (Throwable) {
            return ['Published-video audit could not complete; production release is blocked.'];
        }
    }

    /** @return list<string> */
    private function financialProvenanceFailures(): array
    {
        $releaseFailures = [];
        if (Schema::hasTable('payment_methods')) {
            $unconfiguredActiveMethods = DB::table('payment_methods')
                ->where('is_active', true)
                ->where(function ($query): void {
                    $query->whereNull('account_details')
                        ->orWhereRaw("TRIM(account_details) = ''")
                        ->orWhere('account_details', PaymentMethod::DEFAULT_ACCOUNT_DETAILS);
                })
                ->count();
            if ($unconfiguredActiveMethods > 0) {
                $releaseFailures[] = "{$unconfiguredActiveMethods} active payment method(s) have no usable account details.";
            }
        }
        foreach (['payment_reconciliation_checkpoints', 'payment_reconciliation_findings'] as $table) {
            if (!Schema::hasTable($table)) {
                $releaseFailures[] = implode(' ', [
                    'Payment provider reconciliation tables are missing.',
                    'Run the current forward migrations before release.',
                ]);

                break;
            }
        }
        if (!Schema::hasTable('financial_anomalies')) {
            $releaseFailures[] = 'Financial anomaly isolation is missing. Run the current forward migrations before release.';
        }

        $required = [
            'wallet_credit_lots',
            'wallet_debit_allocations',
            'financial_entitlement_holds',
        ];
        foreach ($required as $table) {
            if (!Schema::hasTable($table)) {
                return [
                    ...$releaseFailures,
                    'Financial provenance tables are missing. Run migrations and finance:backfill-provenance --apply before release.',
                ];
            }
        }
        if (
            !Schema::hasTable('orders')
            || !Schema::hasTable('wallet_transactions')
            || !Schema::hasTable('users')
        ) {
            return [
                ...$releaseFailures,
                'Financial provenance audit cannot run because core finance tables are missing.',
            ];
        }

        try {
            $failures = $releaseFailures;
            $missingLots = DB::table('wallet_transactions as wallet_credit')
                ->leftJoin(
                    'wallet_credit_lots as lot',
                    'lot.credit_transaction_id',
                    '=',
                    'wallet_credit.id'
                )
                ->where('wallet_credit.direction', WalletTransaction::DIRECTION_CREDIT)
                ->whereIn('wallet_credit.category', [
                    'package_purchase',
                    'course_service_compensation',
                ])
                ->where('wallet_credit.paid_amount', '>', 0)
                ->whereNull('lot.id')
                ->count();
            if ($missingLots > 0) {
                $failures[] = "{$missingLots} paid wallet credit(s) have no immutable credit lot.";
            }

            $unreconciledReversals = DB::table('orders as orders')
                ->leftJoin('wallet_credit_lots as lot', 'lot.source_order_id', '=', 'orders.id')
                ->whereNotNull('orders.package_id')
                ->where('orders.status', 'approved')
                ->where(function ($query): void {
                    $query->whereNotNull('orders.reversed_at')
                        ->orWhereIn('orders.financial_status', [
                            'refunded',
                            'chargeback',
                            'reversed',
                            'partially_recovered',
                            'review_required',
                        ]);
                })
                ->where(function ($query): void {
                    $query->whereNull('lot.id')->orWhere('lot.status', 'active');
                })
                ->count();
            if ($unreconciledReversals > 0) {
                $failures[] = "{$unreconciledReversals} historical package reversal(s) still require explicit finance reconciliation.";
            }

            $incompleteDebits = DB::query()->fromSub(
                DB::table('wallet_transactions as wt')
                    ->leftJoin('wallet_debit_allocations as allocation', 'allocation.wallet_transaction_id', '=', 'wt.id')
                    ->where('wt.direction', 'debit')
                    ->whereIn('wt.category', ['course_purchase', 'course_chat_upgrade', 'course_full_track_upgrade'])
                    ->where('wt.paid_amount', '>', 0)
                    ->groupBy('wt.id', 'wt.paid_amount')
                    ->havingRaw('COALESCE(SUM(allocation.amount), 0) <> wt.paid_amount')
                    ->select('wt.id'),
                'incomplete_paid_debits'
            )->count();
            if ($incompleteDebits > 0) {
                $failures[] = "{$incompleteDebits} paid wallet debit(s) have incomplete FIFO source allocation.";
            }

            $incompleteOrders = DB::query()->fromSub(
                DB::table('orders as orders')
                    ->leftJoin('wallet_debit_allocations as allocation', 'allocation.course_order_id', '=', 'orders.id')
                    ->whereNotNull('orders.course_id')
                    ->where('orders.payment_method', 'wallet_coins')
                    ->where('orders.status', 'approved')
                    ->where('orders.paid_coins', '>', 0)
                    ->groupBy('orders.id', 'orders.paid_coins')
                    ->havingRaw('COALESCE(SUM(allocation.amount), 0) <> orders.paid_coins')
                    ->select('orders.id'),
                'incomplete_paid_orders'
            )->count();
            if ($incompleteOrders > 0) {
                $failures[] = "{$incompleteOrders} paid course order(s) have incomplete source allocation.";
            }

            $unlinkedPaidOrders = DB::table('orders')
                ->whereNotNull('course_id')
                ->where('payment_method', 'wallet_coins')
                ->where('status', 'approved')
                ->where('paid_coins', '>', 0)
                ->whereNull('wallet_transaction_id')
                ->count();
            if ($unlinkedPaidOrders > 0) {
                $failures[] = "{$unlinkedPaidOrders} paid course order(s) are not linked to their wallet debit.";
            }

            $balanceMismatches = DB::query()->fromSub(
                DB::table('users as users')
                    ->leftJoin('wallet_credit_lots as lot', function ($join): void {
                        $join->on('lot.user_id', '=', 'users.id')
                            ->where('lot.status', '=', 'active');
                    })
                    ->groupBy('users.id', 'users.wallet_purchased_coins')
                    ->havingRaw('COALESCE(SUM(lot.remaining_amount), 0) <> users.wallet_purchased_coins')
                    ->select('users.id'),
                'paid_balance_mismatches'
            )->count();
            if ($balanceMismatches > 0) {
                $failures[] = "{$balanceMismatches} learner paid balance(s) do not match active source lots.";
            }

            $ledgerTailMismatches = DB::table('wallet_transactions as wt')
                ->join('users as users', 'users.id', '=', 'wt.user_id')
                ->whereNotExists(function ($newer): void {
                    $newer->selectRaw('1')
                        ->from('wallet_transactions as newer')
                        ->whereColumn('newer.user_id', 'wt.user_id')
                        ->whereColumn('newer.id', '>', 'wt.id');
                })
                ->where(function ($mismatch): void {
                    $mismatch->whereColumn('users.wallet_coins', '<>', 'wt.balance_after')
                        ->orWhereColumn('users.wallet_purchased_coins', '<>', 'wt.paid_balance_after')
                        ->orWhereColumn('users.wallet_reward_coins', '<>', 'wt.reward_balance_after')
                        ->orWhereRaw('wt.balance_after <> wt.paid_balance_after + wt.reward_balance_after');
                })
                ->count();
            if ($ledgerTailMismatches > 0) {
                $failures[] = "{$ledgerTailMismatches} wallet ledger tail(s) do not match the learner balance projection.";
            }

            $unledgeredBalances = DB::table('users as users')
                ->leftJoin('wallet_transactions as wt', 'wt.user_id', '=', 'users.id')
                ->whereNull('wt.id')
                ->where(function ($balance): void {
                    $balance->where('users.wallet_coins', '<>', 0)
                        ->orWhere('users.wallet_purchased_coins', '<>', 0)
                        ->orWhere('users.wallet_reward_coins', '<>', 0);
                })
                ->count();
            if ($unledgeredBalances > 0) {
                $failures[] = "{$unledgeredBalances} non-zero learner wallet(s) have no ledger entry.";
            }

            if ($failures !== []) {
                $failures[] = 'Run finance:backfill-provenance --apply and repeat the dry-run audit before release.';
            }

            return $failures;
        } catch (Throwable) {
            return ['Financial provenance audit could not complete; production release is blocked.'];
        }
    }

    private function configured(string $key): bool
    {
        $value = config($key);

        return is_string($value) ? trim($value) !== '' : $value !== null;
    }

    private function validBareHostname(string $hostname): bool
    {
        $hostname = strtolower(trim($hostname));

        return $hostname !== ''
            && !str_contains($hostname, ':')
            && !str_contains($hostname, '/')
            && !str_contains($hostname, '@')
            && $this->validPublicHost($hostname);
    }

    private function validTrustedProxy(string $proxy): bool
    {
        $proxy = trim($proxy);
        if ($proxy === '' || in_array($proxy, ['*', '0.0.0.0/0', '::/0'], true)) {
            return false;
        }
        if (!str_contains($proxy, '/')) {
            return filter_var($proxy, FILTER_VALIDATE_IP) !== false;
        }
        [$network, $prefix] = array_pad(explode('/', $proxy, 2), 2, null);
        $max = filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 32
            : (filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 0);
        $minimum = $max === 32 ? 8 : 32;

        return $max > 0 && ctype_digit((string) $prefix)
            && (int) $prefix >= $minimum && (int) $prefix <= $max;
    }

    private function validAndroidPackage(string $package): bool
    {
        return preg_match('/\A[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+\z/', $package) === 1;
    }

    private function validAndroidFingerprint(string $fingerprint): bool
    {
        return preg_match('/\A(?:[0-9A-F]{2}:){31}[0-9A-F]{2}\z/', $fingerprint) === 1;
    }

    private function validAndroidAssociation(Response $response, string $expectedIdentity): bool
    {
        if (!$response->successful()) {
            return false;
        }

        $identity = trim((string) $response->header('X-Rokn-App-Identity'));
        if ($identity !== '' && !hash_equals($expectedIdentity, $identity)) {
            return false;
        }

        $expectedPackage = trim((string) config('app_links.android_package'));
        $expectedFingerprints = array_values(array_unique(array_map(
            static fn ($value): string => strtoupper(trim((string) $value)),
            (array) config('app_links.android_sha256_fingerprints', [])
        )));
        sort($expectedFingerprints);

        foreach ((array) $response->json() as $statement) {
            $target = is_array($statement) ? ($statement['target'] ?? null) : null;
            if (!is_array($target)
                || ($target['namespace'] ?? null) !== 'android_app'
                || !hash_equals($expectedPackage, trim((string) ($target['package_name'] ?? '')))) {
                continue;
            }

            $actualFingerprints = array_values(array_unique(array_map(
                static fn ($value): string => strtoupper(trim((string) $value)),
                (array) ($target['sha256_cert_fingerprints'] ?? [])
            )));
            sort($actualFingerprints);

            if ($actualFingerprints === $expectedFingerprints) {
                return true;
            }
        }

        return false;
    }

    private function validAppleAppId(string $appId): bool
    {
        return preg_match('/\A[A-Z0-9]{10}\.(?:[A-Za-z0-9-]+\.)+[A-Za-z0-9-]+\z/', $appId) === 1;
    }

    private function validSocialPublicApiUrl(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && $this->validPublicHost((string) ($parts['host'] ?? ''))
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && rtrim((string) ($parts['path'] ?? ''), '/') === '/api/v1';
    }

    private function validSocialReturnUrl(string $url): bool
    {
        return $url === 'rokn://auth';
    }

    private function validPublicHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '' || str_contains($host, ':') || !str_contains($host, '.')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
        }

        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return false;
        }

        foreach (['.localhost', '.local', '.test', '.example', '.invalid'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        return !in_array($host, [
            'localhost',
            'example.com',
            'example.net',
            'example.org',
        ], true);
    }
}
