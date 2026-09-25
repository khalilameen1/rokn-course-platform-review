<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\AccountFileDeletion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AccountDeletionService
{
    public function __construct(
        private readonly AcquisitionRewardTombstoneService $rewardTombstones,
        private readonly AccountAiDataErasureService $aiData,
        private readonly AccountPortfolioErasureService $portfolioData,
        private readonly AccountUploadedContentErasureService $uploadedContent,
        private readonly StoredFileDeletionService $files,
        private readonly SocialIdentityGuardService $identityGuards,
        private readonly AppleService $apple,
        private readonly CourseCatalogueRevisionService $catalogueRevisions
    ) {
    }

    /**
     * Remove account identity while retaining the relational shell required by
     * payment, wallet and enrolment records.
     *
     * @return array{local_cleanup_pending: bool, remote_portfolio_cleanup_pending: bool}
     */
    public function delete(User $user): array
    {
        // Mark every currently linked provider before taking the user lock.
        // A provider callback already in flight can then be rejected without
        // deadlocking against the account aggregate deletion transaction.
        $this->identityGuards->markDeletionStarted((int) $user->id);
        $remotePortfolioCleanupPending = false;
        $cleanupOutboxIds = [];

        DB::transaction(function () use (
            $user,
            &$remotePortfolioCleanupPending,
            &$cleanupOutboxIds
        ): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            // Cover a provider linked in the narrow interval between the first
            // identity snapshot and this aggregate lock.
            $this->identityGuards->markDeletionStarted((int) $locked->id);
            $this->apple->revokeForAccountDeletion($locked);
            $userId = (int) $locked->id;
            $catalogueEnrollmentCountChanged = strtolower((string) $locked->role) === 'client'
                && Schema::hasTable('course_enrollments')
                && Schema::hasColumn('course_enrollments', 'is_active')
                && Schema::hasColumn('course_enrollments', 'expires_at')
                && DB::table('course_enrollments')
                    ->where('user_id', $userId)
                    ->where('is_active', true)
                    ->where(function ($query): void {
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->exists();
            $originalPhone = trim((string) $locked->getRawOriginal('phone'));
            $storedFiles = $this->uploadedContent->eraseLearningFilesWithinDeletion($locked);

            $remotePortfolioCleanupPending = $this->portfolioData->eraseWithinDeletion($userId);

            $storedFiles = array_merge(
                $storedFiles,
                $this->uploadedContent->eraseSupportFilesWithinDeletion($userId)
            );

            $this->aiData->eraseWithinDeletion($userId);
            if (Schema::hasTable('playback_sessions')) {
                // Aggregate playback timings remain useful for media health;
                // host/device diagnostics are not part of the learning record.
                DB::table('playback_sessions')->where('user_id', $userId)->update(
                    $this->onlyExistingColumns('playback_sessions', [
                        'source_host' => null,
                        'diagnostics' => null,
                        'last_error_code' => null,
                        'updated_at' => now(),
                    ])
                );
            }
            if (Schema::hasTable('product_events')) {
                // Retain anonymous funnel totals without a device/session join key.
                DB::table('product_events')->where('user_id', $userId)->update(
                    $this->onlyExistingColumns('product_events', [
                        'user_id' => null,
                        'actor_key' => null,
                        'session_key' => null,
                    ])
                );
            }
            if (Schema::hasTable('attendances') && Schema::hasColumn('attendances', 'notes')) {
                DB::table('attendances')->where('user_id', $userId)->update([
                    'notes' => null,
                    'updated_at' => now(),
                ]);
            }
            if (Schema::hasTable('project_feedback_threads')) {
                // Explicit deletion is required because users are soft-deleted;
                // the database cascade would otherwise never run.
                DB::table('project_feedback_threads')->where('user_id', $userId)->delete();
            }
            if (Schema::hasTable('course_chat_turns')) {
                DB::table('course_chat_turns')->where('user_id', $userId)->delete();
            }

            $storedFiles = array_merge(
                $storedFiles,
                $this->uploadedContent->eraseLegacyPhotosWithinDeletion($userId)
            );

            // Keep one-time acquisition rewards one-time even if the learner
            // later signs up again with the same provider identity. The
            // tombstone contains only a keyed HMAC and consumed reward keys;
            // this must happen before social_accounts is erased.
            $this->rewardTombstones->rememberConsumedRewards($locked);

            $this->deleteByUserIdIfPresent('user_device_tokens', $userId);
            $this->deleteByUserIdIfPresent('social_accounts', $userId);
            $this->deleteByUserIdIfPresent('sessions', $userId);
            $this->deleteByUserIdIfPresent('watching_logs', $userId);
            // Academic evidence is deliberately not part of "clear watch
            // history", but full account deletion must remove it as personal
            // learning data.
            $this->deleteByUserIdIfPresent('lesson_watch_evidence', $userId);
            $this->deleteByUserIdIfPresent('login_logs', $userId);
            $this->deleteByUserIdIfPresent('payment_infos', $userId);
            // These are user-controlled or communication records, not the
            // financial/learning evidence retained for legal disputes.
            $this->deleteByUserIdIfPresent('saved_folders', $userId);
            $this->deleteByUserIdIfPresent('classification_user', $userId);
            $this->deleteByUserIdIfPresent('portfolio_video_uploads', $userId);
            $this->deleteByUserIdIfPresent('ai_conversation_contexts', $userId);
            $this->deleteByUserIdIfPresent('student_notifications', $userId);
            $this->deleteByUserIdIfPresent('messages', $userId);
            $this->deleteByUserIdIfPresent('user_notes', $userId);
            $courseRatingsDeleted = false;
            if (Schema::hasTable('course_ratings') && Schema::hasColumn('course_ratings', 'user_id')) {
                // Query-builder deletion is intentional during account
                // erasure, but it bypasses CourseRating model events.
                $courseRatingsDeleted = DB::table('course_ratings')
                    ->where('user_id', $userId)
                    ->delete() > 0;
            }
            $this->deleteByUserIdIfPresent('rates', $userId);
            $this->deleteByUserIdIfPresent('order_notifications', $userId);
            $this->deleteByUserIdIfPresent('order_requests', $userId);
            $this->deleteByUserIdIfPresent('driver_requests', $userId);
            $this->deleteByUserIdIfPresent('service_user', $userId);
            $this->deleteByUserIdIfPresent('store_user', $userId);
            $this->deleteByUserIdIfPresent('whatsapp_link_tokens', $userId);
            $this->deleteByUserIdIfPresent('user_whatsapp_connections', $userId);

            $tokenTable = (string) config('multiple-tokens-auth.table', 'api_tokens');
            $this->deleteByUserIdIfPresent($tokenTable, $userId);

            if ($originalPhone !== '' && Schema::hasTable('verification_codes')) {
                DB::table('verification_codes')->where('phone', $originalPhone)->delete();
            }

            $suffix = $userId . '-' . Str::lower(Str::random(12));
            $anonymized = [
                'name' => 'حساب محذوف',
                'name_ar' => null,
                'name_en' => null,
                'email' => 'deleted-' . $suffix . '@deleted.rokn.local',
                'email_verified_at' => null,
                'phone' => 'deleted-' . $suffix,
                'phone_verified_at' => null,
                'password' => Hash::make(Str::random(64)),
                'social_provider' => null,
                'social_id' => null,
                'ai_consent_version' => null,
                'ai_consent_accepted_at' => null,
                'api_token' => null,
                'access_token' => null,
                'remember_token' => null,
                'device_os' => null,
                'locked_device_id' => null,
                'active' => false,
                'is_online' => false,
                'provider_request' => false,
                'notifications_status' => false,
                'watch_history_enabled' => false,
                'marketing_notifications_enabled' => false,
                'profile_image' => null,
                'job_title' => null,
                'bio' => null,
                'bio_ar' => null,
                'bio_en' => null,
                'birthday' => null,
                'gender' => 'other',
                'first_name' => null,
                'second_name' => null,
                'last_name' => null,
                'parent_phone' => null,
                'parent_job' => null,
                'type' => null,
                'governorate' => null,
                'car_model' => null,
                'car_year' => null,
                'bank_account_name' => null,
                'bank_account_id' => null,
                'portfolio_slug' => null,
                'portfolio_headline' => null,
                'portfolio_location' => null,
                'portfolio_skills' => null,
                'portfolio_links' => null,
            ];

            $cleanupOutboxIds = $this->files->queueReleasedFiles($storedFiles, $userId);

            // Remains safe during rolling deploys with slightly different legacy schemas.
            $userColumns = array_flip(Schema::getColumnListing('users'));
            $locked->forceFill(array_intersect_key($anonymized, $userColumns))->save();
            // The legacy HasPhoto deleting hook performs immediate filesystem
            // I/O. It has already been replaced above with transactional,
            // retriable outbox work, so suppress that hook here.
            $locked->deleteQuietly();

            if ($courseRatingsDeleted || $catalogueEnrollmentCountChanged) {
                // A verified support request may own an outer transaction.
                // Invalidate only after that whole workflow (including its
                // audit record) commits; discard this callback on rollback.
                $this->catalogueRevisions->invalidateAfterCommit();
            }
        });

        $cleanupPending = AccountFileDeletion::query()
            ->whereIn('id', $cleanupOutboxIds)
            ->whereNotIn('status', [
                AccountFileDeletion::STATUS_COMPLETED,
                AccountFileDeletion::STATUS_SKIPPED,
            ])
            ->exists();

        return [
            'local_cleanup_pending' => $cleanupPending,
            'remote_portfolio_cleanup_pending' => $remotePortfolioCleanupPending,
        ];
    }

    private function deleteByUserIdIfPresent(string $table, int $userId): void
    {
        if ($table !== '' && Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')) {
            DB::table($table)->where('user_id', $userId)->delete();
        }
    }

    /**
     * Keep anonymisation compatible with rolling deployments where the app
     * process and a just-running migration may briefly see adjacent schemas.
     */
    private function onlyExistingColumns(string $table, array $values): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $columns = array_flip(Schema::getColumnListing($table));

        return array_intersect_key($values, $columns);
    }
}
