<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiInputAttachment;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use Illuminate\Support\Facades\DB;

/** Retires a finished project's learner payload while preserving its outcome. */
final class ProjectSubmissionFileRetentionService
{
    public function __construct(
        private readonly StoredFileDeletionService $files,
        private readonly CourseAccessPlanService $plans
    ) {
    }

    public function purgeIfEligible(ProjectSubmission $submission, bool $expiredTerminalFailure = false): bool
    {
        $purge = DB::transaction(function () use ($submission, $expiredTerminalFailure): array {
            $locked = ProjectSubmission::query()->lockForUpdate()->find($submission->id);
            if (!$locked || !$this->eligible($locked, $expiredTerminalFailure)) {
                return ['changed' => false, 'files' => []];
            }

            $metadata = is_array($locked->submission_metadata) ? $locked->submission_metadata : [];
            $paths = $this->paths($locked, $metadata);
            $attachments = AiInputAttachment::query()
                ->where('owner_type', AiInputAttachment::OWNER_PROJECT_SUBMISSION)
                ->where('owner_id', $locked->id)
                ->lockForUpdate()
                ->get(['id', 'storage_disk', 'storage_path']);
            foreach ($attachments as $attachment) {
                $paths[] = [
                    'disk' => (string) $attachment->storage_disk,
                    'path' => (string) $attachment->storage_path,
                ];
            }

            $hadPayload = trim((string) $locked->submission_text) !== ''
                || trim((string) $locked->submission_file) !== ''
                || $attachments->isNotEmpty()
                || (array) data_get($metadata, 'files', []) !== [];

            AiInputAttachment::query()
                ->whereKey($attachments->pluck('id'))
                ->delete();

            $retained = array_intersect_key($metadata, array_flip([
                'request_fingerprint', 'assessment_type', 'skill_verified',
                'progression_credit', 'ai_feedback',
            ]));
            $retained['files_purged_at'] = now()->toIso8601String();
            $locked->forceFill([
                'submission_text' => null,
                'submission_file' => null,
                'original_file_name' => null,
                'mime_type' => null,
                'file_size' => null,
                'submission_metadata' => $retained,
            ])->save();

            return ['changed' => $hadPayload, 'files' => $this->uniquePaths($paths)];
        }, 3);

        foreach ($purge['files'] as $file) {
            $this->files->deleteOrQueue($file['disk'], $file['path']);
        }

        return $purge['changed'];
    }

    public function purgeExpiredTerminalFailures(int $limit): int
    {
        $cutoff = now()->subDays(max(1, (int) config('retention.project_submission_failed_files_days', 30)));
        $ids = ProjectSubmission::query()
            ->whereIn('review_status', [
                ProjectSubmission::STATUS_PASSED,
                ProjectSubmission::STATUS_NEEDS_RESUBMISSION,
            ])
            ->where(function ($payload): void {
                $payload->whereNotNull('submission_file')
                    ->orWhereNotNull('submission_text')
                    ->orWhereHas('aiInputAttachments');
            })
            ->where(function ($terminal) use ($cutoff): void {
                $terminal->where('review_status', ProjectSubmission::STATUS_NEEDS_RESUBMISSION)
                    ->orWhere(function ($passed) use ($cutoff): void {
                        $passed->where('review_status', ProjectSubmission::STATUS_PASSED)
                            ->where(function ($report) use ($cutoff): void {
                                $report->whereNull('submission_metadata->ai_feedback->status')
                                    ->orWhereIn('submission_metadata->ai_feedback->status', [
                                        'ready',
                                        'not_applicable',
                                    ])
                                    ->orWhere(function ($failed) use ($cutoff): void {
                                        $failed->where('submission_metadata->ai_feedback->status', 'unavailable')
                                            ->where('updated_at', '<=', $cutoff);
                                    });
                            });
                    });
            })
            ->orderBy('id')->limit($limit)->pluck('id');

        $purged = 0;
        foreach ($ids as $id) {
            $submission = ProjectSubmission::query()->find($id);
            $expiredFailure = $submission
                && $submission->review_status === ProjectSubmission::STATUS_PASSED
                && data_get($submission->submission_metadata, 'ai_feedback.status') === 'unavailable'
                && $submission->updated_at?->lte($cutoff);
            if ($submission && $this->purgeIfEligible($submission, (bool) $expiredFailure)) $purged++;
        }
        return $purged;
    }

    /** Remove temporary learner payloads before an unpublished project is deleted. */
    public function purgeForDeletedProject(Project $project): void
    {
        $files = DB::transaction(function () use ($project): array {
            $submissions = ProjectSubmission::query()
                ->where('project_id', $project->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $submissionIds = $submissions->modelKeys();
            $threadIds = DB::table('project_feedback_threads')
                ->where('project_id', $project->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');
            $messageIds = DB::table('project_feedback_messages')
                ->whereIn('thread_id', $threadIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');
            $attachments = AiInputAttachment::query()
                ->where(function ($owners) use ($submissionIds, $messageIds): void {
                    $owners->where(function ($submissions) use ($submissionIds): void {
                        $submissions
                            ->where('owner_type', AiInputAttachment::OWNER_PROJECT_SUBMISSION)
                            ->whereIn('owner_id', $submissionIds);
                    })->orWhere(function ($messages) use ($messageIds): void {
                        $messages
                            ->where('owner_type', AiInputAttachment::OWNER_PROJECT_FEEDBACK_MESSAGE)
                            ->whereIn('owner_id', $messageIds);
                    });
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'storage_disk', 'storage_path']);

            $paths = [];
            foreach ($submissions as $submission) {
                $paths = array_merge(
                    $paths,
                    $this->paths($submission, (array) $submission->submission_metadata)
                );
            }
            foreach ($attachments as $attachment) {
                $paths[] = [
                    'disk' => (string) $attachment->storage_disk,
                    'path' => (string) $attachment->storage_path,
                ];
            }

            AiInputAttachment::query()->whereKey($attachments->modelKeys())->delete();
            ProjectSubmission::query()->whereKey($submissionIds)->delete();

            return $this->uniquePaths($paths);
        }, 3);

        foreach ($files as $file) {
            $this->files->deleteOrQueue($file['disk'], $file['path']);
        }
    }

    /** @param array<string,mixed> $metadata */
    private function paths(ProjectSubmission $submission, array $metadata): array
    {
        $paths = [];
        $defaultDisk = $submission->submission_disk;
        if (trim((string) $submission->submission_file) !== '') {
            $paths[] = ['disk' => $defaultDisk, 'path' => (string) $submission->submission_file];
        }
        foreach ((array) data_get($metadata, 'files', []) as $file) {
            if (!is_array($file) || trim((string) ($file['path'] ?? '')) === '') continue;
            $paths[] = [
                'disk' => trim((string) ($file['storage_disk'] ?? $defaultDisk)) ?: $defaultDisk,
                'path' => (string) $file['path'],
            ];
        }
        return $paths;
    }

    private function eligible(ProjectSubmission $submission, bool $expiredTerminalFailure): bool
    {
        if ($submission->review_status === ProjectSubmission::STATUS_NEEDS_RESUBMISSION) return true;
        if ($submission->review_status !== ProjectSubmission::STATUS_PASSED) return false;

        $snapshot = ProjectSubmissionEvaluationSnapshot::fromSubmission($submission);
        $terms = $snapshot ? data_get($snapshot, 'access.terms') : null;
        $status = (string) data_get($submission->submission_metadata, 'ai_feedback.status', '');
        // Old or malformed entitlement snapshots cannot prove pass_only.
        // Fail closed unless the report lifecycle itself is terminal.
        if (!is_array($terms)) {
            return in_array($status, ['ready', 'not_applicable'], true)
                || ($expiredTerminalFailure && $status === 'unavailable');
        }
        $reportEnabled = is_array($terms)
            && (bool) $this->plans->publicPayloadFromTerms($terms)['project_report_enabled'];
        if (!$reportEnabled) return true;

        return in_array($status, ['ready', 'not_applicable'], true)
            || ($expiredTerminalFailure && $status === 'unavailable');
    }

    private function uniquePaths(array $paths): array
    {
        $unique = [];
        foreach ($paths as $file) {
            $disk = trim((string) ($file['disk'] ?? ''));
            $path = ltrim(trim((string) ($file['path'] ?? '')), '/');
            if ($disk === '' || $path === '') continue;
            $unique[$disk . "\0" . $path] = compact('disk', 'path');
        }
        return array_values($unique);
    }
}
