<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\ProjectSubmission;
use App\Jobs\CleanupDeletedAccountPortfolioMedia;
use App\Jobs\DeleteAccountFile;
use App\Support\DurableJobDispatch;
use App\Models\AccountFileDeletion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AccountDeletionService
{
    public function __construct(
        private readonly AcquisitionRewardTombstoneService $rewardTombstones,
        private readonly AiEntitlementBudgetService $aiBudget,
        private readonly PaidAiCallExecutionService $paidAiCalls,
        private readonly SocialIdentityGuardService $identityGuards
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
        $publicFiles = [];
        $localFiles = [];
        $storedFiles = [];
        $remotePortfolioCleanupPending = false;
        $cleanupOutboxIds = [];
        $courseRatingsDeleted = false;
        $catalogueEnrollmentCountChanged = false;

        DB::transaction(function () use (
            $user,
            &$publicFiles,
            &$localFiles,
            &$storedFiles,
            &$remotePortfolioCleanupPending,
            &$cleanupOutboxIds,
            &$courseRatingsDeleted,
            &$catalogueEnrollmentCountChanged
        ): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            // Cover a provider linked in the narrow interval between the first
            // identity snapshot and this aggregate lock.
            $this->identityGuards->markDeletionStarted((int) $locked->id);
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
            $profileImage = trim((string) $locked->getRawOriginal('profile_image'));

            if ($profileImage !== '' && !filter_var($profileImage, FILTER_VALIDATE_URL)) {
                $publicFiles[] = ltrim($profileImage, '/');
            }

            if (Schema::hasTable('ai_input_attachments')) {
                DB::table('ai_input_attachments')
                    ->where('user_id', $userId)
                    ->get(['storage_disk', 'storage_path'])
                    ->each(function ($attachment) use (&$storedFiles): void {
                        $storedFiles[] = [
                            'disk' => (string) $attachment->storage_disk,
                            'path' => (string) $attachment->storage_path,
                        ];
                    });
                $this->deleteByUserIdIfPresent('ai_input_attachments', $userId);
            }

            if (Schema::hasTable('project_submissions')) {
                $projectSubmissionIds = DB::table('project_submissions')
                    ->where('user_id', $userId)
                    ->pluck('id');
                ProjectSubmission::query()
                    ->where('user_id', $userId)
                    ->get(['id', 'submission_file', 'submission_metadata'])
                    ->each(function (ProjectSubmission $submission) use (&$storedFiles): void {
                        if (trim((string) $submission->submission_file) !== '') {
                            foreach ($submission->submissionDiskCandidates() as $disk) {
                                $storedFiles[] = [
                                    'disk' => $disk,
                                    'path' => (string) $submission->submission_file,
                                ];
                            }
                        }
                        foreach ((array) data_get($submission->submission_metadata, 'files', []) as $file) {
                            if (!is_array($file) || trim((string) ($file['path'] ?? '')) === '') continue;
                            $storedFiles[] = [
                                'disk' => trim((string) ($file['storage_disk'] ?? ''))
                                    ?: $submission->submission_disk,
                                'path' => (string) $file['path'],
                            ];
                        }
                    });

                DB::table('project_submissions')->where('user_id', $userId)->update([
                    'submission_text' => null,
                    'submission_file' => null,
                    'original_file_name' => null,
                    'mime_type' => null,
                    'file_size' => null,
                    'submission_metadata' => null,
                    'updated_at' => now(),
                ]);
            }

            if (Schema::hasTable('certificates') && Schema::hasColumn('certificates', 'image_path')) {
                $certificateFiles = DB::table('certificates')
                        ->where('user_id', $userId)
                        ->whereNotNull('image_path')
                        ->where('image_path', '!=', 'pending')
                        ->pluck('image_path')
                        ->filter()
                        ->all();
                foreach ($certificateFiles as $certificatePath) {
                    foreach (array_unique([(string) config('certificate.disk', 'public'), 'public']) as $disk) {
                        $storedFiles[] = ['disk' => $disk, 'path' => (string) $certificatePath];
                    }
                }

                $certificateUpdate = ['image_path' => 'pending', 'updated_at' => now()];
                if (Schema::hasColumn('certificates', 'holder_name')) {
                    $certificateUpdate['holder_name'] = null;
                }
                if (Schema::hasColumn('certificates', 'status')) {
                    $certificateUpdate['status'] = 'revoked';
                }
                if (Schema::hasColumn('certificates', 'revoked_at')) {
                    $certificateUpdate['revoked_at'] = now();
                }
                DB::table('certificates')->where('user_id', $userId)->update($certificateUpdate);
            }

            if (Schema::hasTable('portfolio_items')) {
                $portfolioItemIds = DB::table('portfolio_items')->where('user_id', $userId)->pluck('id');
                if ($portfolioItemIds->isNotEmpty() && Schema::hasTable('portfolio_media')) {
                    $itemsWithMedia = DB::table('portfolio_media')
                        ->whereIn('portfolio_item_id', $portfolioItemIds)
                        ->distinct()
                        ->pluck('portfolio_item_id');
                    $emptyItemIds = $portfolioItemIds->diff($itemsWithMedia);
                    if ($emptyItemIds->isNotEmpty()) {
                        DB::table('portfolio_items')->whereIn('id', $emptyItemIds)->delete();
                    }
                    $portfolioItemIds = $itemsWithMedia;
                    // Bunny deletions are external and cannot be atomic with the DB
                    // transaction. Keep private references for a retriable cleanup.
                    $remotePortfolioCleanupPending = DB::table('portfolio_media')
                        ->whereIn('portfolio_item_id', $portfolioItemIds)
                        ->exists();
                    $mediaUpdate = [];
                    if (Schema::hasColumn('portfolio_media', 'caption')) {
                        $mediaUpdate['caption'] = null;
                    }
                    if (Schema::hasColumn('portfolio_media', 'updated_at')) {
                        $mediaUpdate['updated_at'] = now();
                    }
                    if ($mediaUpdate !== []) {
                        DB::table('portfolio_media')
                            ->whereIn('portfolio_item_id', $portfolioItemIds)
                            ->update($mediaUpdate);
                    }
                } elseif ($portfolioItemIds->isNotEmpty()) {
                    DB::table('portfolio_items')->whereIn('id', $portfolioItemIds)->delete();
                    $portfolioItemIds = collect();
                }

                $portfolioUpdate = $this->onlyExistingColumns('portfolio_items', [
                    'title' => null,
                    'description' => null,
                    'slug' => null,
                    'role' => null,
                    'tools' => null,
                    'external_url' => null,
                    'is_public' => false,
                    'is_featured' => false,
                    'updated_at' => now(),
                ]);
                if ($portfolioUpdate !== []) {
                    DB::table('portfolio_items')
                        ->where('user_id', $userId)
                        ->whereIn('id', $portfolioItemIds)
                        ->update($portfolioUpdate);
                }
            }

            if (Schema::hasTable('feedback_reports')) {
                $feedbackReportIds = DB::table('feedback_reports')
                    ->where('user_id', $userId)
                    ->pluck('id');
                if ($feedbackReportIds->isNotEmpty() && Schema::hasTable('feedback_attachments')) {
                    DB::table('feedback_attachments')
                        ->whereIn('feedback_report_id', $feedbackReportIds)
                        ->get(['disk', 'path'])
                        ->each(function ($attachment) use (&$storedFiles): void {
                            $storedFiles[] = [
                                'disk' => (string) $attachment->disk,
                                'path' => (string) $attachment->path,
                            ];
                        });
                    DB::table('feedback_attachments')
                        ->whereIn('feedback_report_id', $feedbackReportIds)
                        ->delete();
                }
                DB::table('feedback_reports')->whereIn('id', $feedbackReportIds)->delete();
            }

            // Usage totals and costs remain as financial/operational evidence,
            // but accepted AI replies and request context are personal content.
            if (Schema::hasTable('ai_usage_events')) {
                // Preserve whether a paid provider call had started before
                // scrubbing metadata. Started work becomes unknown exposure;
                // work that never left our queue simply releases its reserve.
                \App\Models\AiUsageEvent::query()
                    ->where('user_id', $userId)
                    ->where('status', 'reserved')
                    ->lockForUpdate()
                    ->get()
                    ->each(function ($event): void {
                        $landed = $this->paidAiCalls->landedResult($event);
                        if ($landed !== null) {
                            // The provider result and actual usage are known,
                            // but deletion forbids presenting or retaining the
                            // answer. Settle the cost exactly, without charging
                            // learner entitlement, then scrub the landing.
                            $landed['entitlement_delivered'] = false;
                            $landed['request_context'] = ['reason' => 'account_deleted'];
                            $this->aiBudget->settle($event, $landed);
                            $this->paidAiCalls->markPresented($event->fresh());
                            return;
                        }
                        if ($this->paidAiCalls->providerWasStarted($event)) {
                            $this->paidAiCalls->settleUnknown(
                                $this->aiBudget, $event, ['reason' => 'account_deleted']
                            );
                        } else {
                            $this->aiBudget->release($event, 'account_deleted');
                        }
                    });
                \App\Models\AiUsageEvent::query()
                    ->where('user_id', $userId)
                    ->get()
                    ->each(function ($event): void {
                        $metadata = is_array($event->metadata) ? $event->metadata : [];
                        $context = is_array($metadata['request_context'] ?? null)
                            ? $metadata['request_context'] : [];
                        $operational = array_filter([
                            'entitlement_delivered' => $metadata['entitlement_delivered'] ?? null,
                            'token_usage_source' => $metadata['token_usage_source'] ?? null,
                            'cost_usage_source' => $metadata['cost_usage_source'] ?? null,
                            'usage_source' => $metadata['usage_source'] ?? null,
                            'provider_call_state' => $metadata['provider_call_state'] ?? null,
                            'provider_outcome_reason' => $metadata['provider_outcome_reason'] ?? null,
                            'provider_call_attempt' => $metadata['provider_call_attempt'] ?? null,
                            'provider_outcome_recorded_at' => $metadata['provider_outcome_recorded_at'] ?? null,
                            'reservation_detached' => $metadata['reservation_detached'] ?? null,
                            'entitlement_transition_reason' => $metadata['entitlement_transition_reason'] ?? null,
                            'entitlement_transitioned_at' => $metadata['entitlement_transitioned_at'] ?? null,
                            'prompt_version' => $context['prompt_version'] ?? null,
                            'feedback_level' => $context['feedback_level'] ?? null,
                        ], static fn ($value): bool => $value !== null && $value !== '');
                        $event->forceFill([
                            'metadata' => $operational ?: null,
                            'updated_at' => now(),
                        ])->save();
                    });
            }
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

            // HasPhoto historically deleted these files synchronously from a
            // model event. Capture them in the durable outbox instead, then
            // remove only the database references inside this transaction.
            if (Schema::hasTable('photos')) {
                $legacyPhotoQuery = DB::table('photos')
                    ->where('photoable_type', User::class)
                    ->where('photoable_id', $userId);
                $legacyPhotoPaths = (clone $legacyPhotoQuery)
                    ->whereNotNull('path')
                    ->pluck('path')
                    ->filter()
                    ->map(static fn ($path): string => (string) $path)
                    ->all();
                $publicFiles = array_merge($publicFiles, $legacyPhotoPaths);
                $legacyPhotoQuery->delete();
            }

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

            $cleanupOutboxIds = $this->enqueueFileCleanup(
                $userId,
                $publicFiles,
                $localFiles,
                $storedFiles
            );

            // Remains safe during rolling deploys with slightly different legacy schemas.
            $userColumns = array_flip(Schema::getColumnListing('users'));
            $locked->forceFill(array_intersect_key($anonymized, $userColumns))->save();
            // The legacy HasPhoto deleting hook performs immediate filesystem
            // I/O. It has already been replaced above with transactional,
            // retriable outbox work, so suppress that hook here.
            $locked->deleteQuietly();
        });

        if ($courseRatingsDeleted || $catalogueEnrollmentCountChanged) {
            // Public course cards cache rating aggregates and active student
            // counts. Account erasure intentionally uses quiet/query-builder
            // mutations, so publish one revision explicitly after commit.
            try {
                Cache::add(
                    'courses:catalog-revision',
                    max(1, (int) floor(microtime(true) * 1000)),
                    now()->addYears(10)
                );
                Cache::increment('courses:catalog-revision');
            } catch (\Throwable) {
                // The committed privacy operation must not depend on Redis.
            }
        }

        $this->afterCommitOrNow(function () use ($cleanupOutboxIds): void {
            foreach ($cleanupOutboxIds as $deletionId) {
                try {
                    DurableJobDispatch::now(new DeleteAccountFile((int) $deletionId));
                } catch (\Throwable $exception) {
                    Log::warning('Unable to dispatch account-file cleanup.', [
                        'deletion_id' => $deletionId,
                        'exception' => get_class($exception),
                    ]);
                }
            }
        });
        $cleanupPending = AccountFileDeletion::query()
            ->whereIn('id', $cleanupOutboxIds)
            ->whereNotIn('status', [
                AccountFileDeletion::STATUS_COMPLETED,
                AccountFileDeletion::STATUS_SKIPPED,
            ])
            ->exists();

        if ($remotePortfolioCleanupPending) {
            $this->afterCommitOrNow(function () use ($user): void {
                try {
                    DurableJobDispatch::now(
                        new CleanupDeletedAccountPortfolioMedia((int) $user->id)
                    );
                } catch (\Throwable $exception) {
                    // The durable private references let scheduled recovery
                    // retry even if the queue is unavailable after commit.
                    Log::warning('Unable to dispatch deleted portfolio cleanup.', [
                        'deleted_user_id' => $user->id,
                        'exception' => get_class($exception),
                    ]);
                }
            });
        }

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

    private function afterCommitOrNow(callable $callback): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);
            return;
        }

        $callback();
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

    private function enqueueFileCleanup(int $userId, array $publicFiles, array $localFiles, array $storedFiles): array
    {
        $candidates = [];
        foreach ($publicFiles as $path) {
            $candidates[] = ['disk' => 'public', 'path' => $path];
        }
        foreach ($localFiles as $path) {
            $candidates[] = ['disk' => 'local', 'path' => $path];
        }
        $candidates = array_merge($candidates, $storedFiles);

        $ids = [];
        foreach ($candidates as $candidate) {
            $disk = trim((string) ($candidate['disk'] ?? ''));
            $path = ltrim(trim((string) ($candidate['path'] ?? '')), '/');
            if ($disk === '' || $path === '' || filter_var($path, FILTER_VALIDATE_URL)) {
                continue;
            }
            $row = AccountFileDeletion::query()->updateOrCreate(
                ['disk' => $disk, 'path_hash' => hash('sha256', $path)],
                [
                    'user_id' => $userId,
                    'path' => $path,
                    'status' => AccountFileDeletion::STATUS_PENDING,
                    'attempts' => 0,
                    'available_at' => now(),
                    'completed_at' => null,
                    'last_error' => null,
                ]
            );
            $ids[] = (int) $row->id;
        }

        return array_values(array_unique($ids));
    }
}
