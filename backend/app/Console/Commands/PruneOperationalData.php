<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PortfolioItem;
use App\Models\AiInputAttachment;
use App\Models\User;
use App\Services\BunnyService;
use App\Services\ProjectSubmissionFileRetentionService;
use App\Support\PublicDiskUrl;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class PruneOperationalData extends Command
{
    protected $signature = 'data:prune-operational {--limit=5000 : Maximum rows per table per run}';
    protected $description = 'Bound privacy-sensitive and high-volume operational tables without touching financial ledgers.';

    public function handle(BunnyService $bunny, ProjectSubmissionFileRetentionService $submissionFiles): int
    {
        $limit = max(100, min(20000, (int) $this->option('limit')));
        $counts = [];

        $counts['client_events'] = $this->deleteByIds(
            'client_events',
            fn (Builder $query): Builder => $query->where(
                'received_at',
                '<=',
                now()->subDays(max(1, (int) config('retention.client_events_days', 30)))
            ),
            $limit
        );
        $counts['product_events'] = $this->deleteByIds(
            'product_events',
            fn (Builder $query): Builder => $query->where(
                'received_at',
                '<=',
                now()->subDays(max(1, (int) config('retention.product_events_days', 180)))
            ),
            $limit
        );
        $counts['playback_completed'] = $this->deleteByIds(
            'playback_sessions',
            fn (Builder $query): Builder => $query->whereNotNull('ended_at')->where(
                'ended_at',
                '<=',
                now()->subDays(max(1, (int) config('retention.playback_completed_days', 90)))
            ),
            $limit
        );
        $counts['playback_abandoned'] = $this->deleteByIds(
            'playback_sessions',
            fn (Builder $query): Builder => $query->whereNull('ended_at')->where(
                'started_at',
                '<=',
                now()->subDays(max(1, (int) config('retention.playback_abandoned_days', 30)))
            ),
            $limit
        );
        $counts['playback_metric_rollups'] = $this->deleteByIds(
            'playback_metric_rollups',
            fn (Builder $query): Builder => $query->where(
                'bucket_start',
                '<=',
                now()->subDays(max(30, (int) config('retention.playback_metric_rollups_days', 400)))
            ),
            $limit
        );
        $counts['student_notifications'] = $this->pruneStudentNotifications($limit);
        $counts['support_cases'] = $this->pruneSupportCases($limit);
        $counts['notification_campaigns'] = $this->deleteByIds(
            'notification_campaigns',
            fn (Builder $query): Builder => $query
                ->whereIn('status', ['completed', 'failed'])
                ->where(
                    'created_at',
                    '<=',
                    now()->subDays(max(1, (int) config('retention.student_notifications_days', 180)))
                ),
            $limit
        );
        $counts['notification_assets'] = $this->pruneNotificationAssets($limit);
        $counts['social_oauth_attempts'] = $this->deleteByIds(
            'social_oauth_attempts',
            fn (Builder $query): Builder => $query->where(function (Builder $expired): void {
                $expired->where('state_expires_at', '<=', now()->subDay())
                    ->orWhere('completion_expires_at', '<=', now()->subDay());
            }),
            $limit
        );
        $counts['course_chat_turns'] = $this->deleteByIds(
            'course_chat_turns',
            fn (Builder $query): Builder => $query->where('expires_at', '<=', now()),
            $limit
        );
        if (
            $counts['course_chat_turns'] > 0
            && Schema::hasTable('ai_conversation_contexts')
        ) {
            // A checkpoint contains excerpts of its source turns. Rebuild it
            // from the retained rows on the next chat request rather than
            // keeping text whose history row has just crossed retention.
            $counts['course_chat_contexts_reset'] = DB::table('ai_conversation_contexts')
                ->where('scope', 'course_chat')
                ->delete();
        }
        if (Schema::hasTable('ai_conversation_contexts')) {
            $expiredContextIds = DB::table('ai_conversation_contexts')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->limit($limit)
                ->pluck('id');
            $counts['expired_ai_conversation_contexts'] = $expiredContextIds->isEmpty()
                ? 0
                : DB::table('ai_conversation_contexts')
                    ->whereIn('id', $expiredContextIds)
                    ->delete();
        }
        $counts['orphan_ai_inputs'] = $this->pruneAiInputs($limit);
        $counts['project_submission_files'] = $submissionFiles->purgeExpiredTerminalFailures($limit);
        $counts['portfolio_drafts'] = $this->prunePortfolioDrafts($limit, $bunny);
        $counts['certificate_lease_artifacts'] = $this->pruneCertificateLeaseArtifacts($limit);
        $counts['admin_audit_logs'] = $this->deleteByIds(
            'admin_audit_logs',
            fn (Builder $query): Builder => $query->where(
                'occurred_at',
                '<=',
                now()->subDays(max(30, (int) config('retention.admin_audit_days', 730)))
            ),
            $limit
        );
        $counts['visitors'] = $this->deleteByIds(
            'visitors',
            fn (Builder $query): Builder => $query->where(
                'visited_at',
                '<=',
                now()->subDays(max(1, (int) config('retention.visitors_days', 90)))
            ),
            $limit
        );

        $this->pseudonymizeVisitors($limit);
        $this->info(collect($counts)->map(fn (int $count, string $table): string => "{$table}={$count}")->implode(' '));

        return self::SUCCESS;
    }

    /** @param callable(Builder):Builder $scope */
    private function deleteByIds(string $table, callable $scope, int $limit): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        $ids = $scope(DB::table($table))->orderBy('id')->limit($limit)->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::table($table)->whereIn('id', $ids)->delete();
    }

    private function pseudonymizeVisitors(int $limit): void
    {
        if (!Schema::hasTable('visitors')) {
            return;
        }

        $secret = (string) config('app.key');
        if ($secret === '') {
            return;
        }

        DB::table('visitors')
            ->whereNotNull('ip_address')
            ->whereRaw('LENGTH(ip_address) <> 64')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'ip_address'])
            ->each(function (object $visitor) use ($secret): void {
                DB::table('visitors')->where('id', $visitor->id)->update([
                    'ip_address' => hash_hmac('sha256', (string) $visitor->ip_address, $secret),
                    'user_agent' => null,
                ]);
            });
    }

    private function pruneAiInputs(int $limit): int
    {
        if (!Schema::hasTable('ai_input_attachments')) return 0;
        $candidateIds = DB::table('ai_input_attachments')
            ->where(function (Builder $query): void {
                $query->where(function (Builder $ready): void {
                    $ready->where('status', AiInputAttachment::READY)
                        ->where('created_at', '<=', now()->subDay());
                })->orWhere(function (Builder $allocating): void {
                    $allocating->where('status', AiInputAttachment::ALLOCATING)
                        ->where('updated_at', '<=', now()->subMinutes(15));
                })->orWhere(function (Builder $stuck): void {
                    $stuck->where('status', AiInputAttachment::DELETING)
                        ->where('updated_at', '<=', now()->subMinutes(15));
                });
            })
            ->orderBy('id')->limit($limit)->pluck('id');
        $deleted = 0;
        foreach ($candidateIds as $candidateId) {
            try {
                $row = DB::transaction(function () use ($candidateId): ?AiInputAttachment {
                    $candidate = AiInputAttachment::query()->find($candidateId);
                    if (!$candidate) return null;
                    // Keep lock ordering aligned with claim/account deletion.
                    User::query()->whereKey($candidate->user_id)->lockForUpdate()->first();
                    $candidate = AiInputAttachment::query()->lockForUpdate()->find($candidateId);
                    if (!$candidate) return null;
                    $recovering = $candidate->status === AiInputAttachment::DELETING
                        && $candidate->updated_at?->lte(now()->subMinutes(15));
                    $abandonedAllocation = $candidate->status === AiInputAttachment::ALLOCATING
                        && $candidate->updated_at?->lte(now()->subMinutes(15));
                    if (
                        !$recovering
                        && !$abandonedAllocation
                        && (
                            $candidate->status !== AiInputAttachment::READY
                            || $candidate->created_at?->gt(now()->subDay())
                        )
                    ) return null;

                    $ownerExists = match ((string) $candidate->owner_type) {
                        'course_chat_turn' => DB::table('course_chat_turns')->where('id', $candidate->owner_id)->exists(),
                        'project_submission' => DB::table('project_submissions')->where('id', $candidate->owner_id)->exists(),
                        'project_feedback_message' => DB::table('project_feedback_messages')->where('id', $candidate->owner_id)->exists(),
                        default => false,
                    };
                    if ($candidate->owner_id !== null && $ownerExists) return null;
                    $candidate->forceFill(['status' => AiInputAttachment::DELETING])->save();
                    return $candidate->fresh();
                }, 3);
                if (!$row) continue;

                $otherReference = AiInputAttachment::query()
                    ->where('id', '<>', $row->id)
                    ->where('storage_disk', $row->storage_disk)
                    ->where('storage_path', $row->storage_path)
                    ->exists();
                if (!$otherReference) {
                    $disk = Storage::disk((string) $row->storage_disk);
                    if ($disk->exists((string) $row->storage_path)
                        && !$disk->delete((string) $row->storage_path)) {
                        continue;
                    }
                }
                $finalized = AiInputAttachment::query()
                    ->whereKey($row->id)
                    ->where('status', AiInputAttachment::DELETING)
                    ->whereNull('owner_id')
                    ->delete();
                if ($finalized === 0 && $row->owner_id !== null) {
                    // Orphaned owners can disappear between the locked recheck
                    // and finalization. They are still safe to delete only if
                    // the owner fields have not changed.
                    $finalized = AiInputAttachment::query()
                        ->whereKey($row->id)
                        ->where('status', AiInputAttachment::DELETING)
                        ->where('owner_type', $row->owner_type)
                        ->where('owner_id', $row->owner_id)
                        ->delete();
                }
                $deleted += $finalized;
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
        return $deleted;
    }

    private function prunePortfolioDrafts(int $limit, BunnyService $bunny): int
    {
        if (
            !Schema::hasTable('portfolio_items')
            || !Schema::hasColumn('portfolio_items', 'expected_media_count')
        ) {
            return 0;
        }

        $ids = DB::table('portfolio_items')
            ->where('expected_media_count', '>', 0)
            ->where('is_public', false)
            ->whereNull('deletion_started_at')
            ->where('updated_at', '<=', now()->subDays(max(
                1,
                (int) config('retention.portfolio_drafts_days', 30)
            )))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $deleted = 0;
        foreach ($ids as $id) {
            $deleted += DB::transaction(function () use ($id, $bunny): int {
                $observed = PortfolioItem::query()->find($id);
                if (!$observed) return 0;
                $user = User::query()->whereKey($observed->user_id)->lockForUpdate()->first();
                if (!$user) return 0;
                $item = $user->portfolioItems()->lockForUpdate()->find($id);
                if (
                    !$item
                    || $item->is_public
                    || $item->deletion_started_at
                    || $item->updated_at?->isAfter(now()->subDays(max(
                        1,
                        (int) config('retention.portfolio_drafts_days', 30)
                    )))
                ) {
                    return 0;
                }
                $item->forceFill(['deletion_started_at' => now()])->save();
                foreach ($item->mediaFiles()->lockForUpdate()->get() as $media) {
                    if ($media->file_type === 'video' && $media->file_path) {
                        $candidate = $bunny->queueVideoCleanup(
                            $media->file_path,
                            null,
                            'portfolio_draft_abandoned',
                            1,
                            false
                        );
                        if (!$candidate) {
                            throw new \RuntimeException('Unable to persist draft video cleanup.');
                        }
                    } elseif ($media->file_type === 'image' && $media->file_path) {
                        if (!$bunny->queueStorageCleanup(
                            $media->file_path,
                            'portfolio_draft_abandoned'
                        )) {
                            throw new \RuntimeException('Unable to persist draft image cleanup.');
                        }
                    }
                    $media->delete();
                }
                $item->delete();
                return 1;
            }, 3);
        }
        return $deleted;
    }

    private function pruneCertificateLeaseArtifacts(int $limit): int
    {
        if (!Schema::hasTable('certificates') || !Schema::hasColumn('certificates', 'image_path')) {
            return 0;
        }
        $disk = Storage::disk((string) config('certificate.disk', 'public'));
        $deleted = 0;
        foreach (array_slice($disk->files('certificates'), 0, $limit) as $path) {
            if (
                !preg_match(
                    '#^certificates/certificate_[a-f0-9-]{36}_[a-f0-9]{32}\.png$#i',
                    $path
                )
                || $disk->lastModified($path) > now()->subDay()->timestamp
                || DB::table('certificates')->where('image_path', $path)->exists()
            ) {
                continue;
            }
            if ($disk->delete($path)) $deleted++;
        }
        return $deleted;
    }

    private function pruneStudentNotifications(int $limit): int
    {
        if (!Schema::hasTable('student_notifications')) {
            return 0;
        }

        $query = DB::table('student_notifications')
            ->where(
                'created_at',
                '<=',
                now()->subDays(max(1, (int) config('retention.student_notifications_days', 180)))
            )
            ->orderBy('id')
            ->limit($limit);
        $columns = Schema::hasColumn('student_notifications', 'image_url')
            ? ['id', 'image_url']
            : ['id'];
        $rows = $query->get($columns);
        if ($rows->isEmpty()) {
            return 0;
        }

        $deleted = DB::table('student_notifications')
            ->whereIn('id', $rows->pluck('id'))
            ->delete();

        if ($columns === ['id']) {
            return $deleted;
        }

        $referencedAssets = $this->notificationAssetReferenceSet();
        $rows->pluck('image_url')
            ->filter()
            ->unique()
            ->each(function (string $url) use ($referencedAssets): void {
                $path = PublicDiskUrl::pathFrom($url);
                if (
                    is_string($path)
                    && str_starts_with($path, 'student-notifications/')
                    && !isset($referencedAssets[$path])
                ) {
                    Storage::disk('public')->delete($path);
                }
            });

        return $deleted;
    }

    private function pruneNotificationAssets(int $limit): int
    {
        if (
            !Schema::hasTable('student_notifications')
            || !Schema::hasColumn('student_notifications', 'image_url')
        ) {
            return 0;
        }

        $disk = Storage::disk('public');
        $referencedAssets = $this->notificationAssetReferenceSet();
        $deleted = 0;
        foreach (array_slice($disk->files('student-notifications'), 0, $limit) as $path) {
            if (
                isset($referencedAssets[$path])
                || $disk->lastModified($path) > now()->subDay()->timestamp
            ) {
                continue;
            }

            if ($disk->delete($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /** @return array<string,true> */
    private function notificationAssetReferenceSet(): array
    {
        $paths = [];
        foreach (['student_notifications', 'notification_campaigns'] as $table) {
            if (
                !Schema::hasTable($table)
                || !Schema::hasColumns($table, ['id', 'image_url'])
            ) {
                continue;
            }

            DB::table($table)
                ->select(['id', 'image_url'])
                ->whereNotNull('image_url')
                ->where('image_url', 'like', '%student-notifications%')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use (&$paths): void {
                    foreach ($rows as $row) {
                        $path = PublicDiskUrl::pathFrom((string) $row->image_url);
                        if (
                            is_string($path)
                            && str_starts_with($path, 'student-notifications/')
                        ) {
                            $paths[$path] = true;
                        }
                    }
                });
        }

        return $paths;
    }

    private function pruneSupportCases(int $limit): int
    {
        if (!Schema::hasTable('feedback_reports') || !Schema::hasColumn('feedback_reports', 'retention_until')) {
            return 0;
        }
        $ids = DB::table('feedback_reports')
            ->whereIn('status', ['resolved', 'closed', 'dismissed'])
            ->whereNotNull('retention_until')
            ->where('retention_until', '<=', now())
            ->orderBy('id')->limit($limit)->pluck('id');
        if ($ids->isEmpty()) return 0;

        $attachments = Schema::hasTable('feedback_attachments')
            ? DB::table('feedback_attachments')->whereIn('feedback_report_id', $ids)->get(['disk', 'path'])
            : collect();
        $deleted = DB::transaction(fn (): int => DB::table('feedback_reports')->whereIn('id', $ids)->delete());
        $attachments->each(fn (object $attachment) => app(\App\Services\StoredFileDeletionService::class)
            ->deleteOrQueue((string) $attachment->disk, (string) $attachment->path));
        return $deleted;
    }
}
