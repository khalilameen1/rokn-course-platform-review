<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiInputAttachment;
use App\Models\CourseChatTurn;
use App\Models\ProjectFeedbackMessage;
use App\Models\ProjectSubmission;
use App\Support\DownloadFilename;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

final class ProjectAttachmentDownloadService
{
    /** @return array{disk:FilesystemAdapter,path:string,name:string,mime:string}|null */
    public function submissionForAdmin(ProjectSubmission $submission): ?array
    {
        if (!$submission->submission_file) {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', trim((string) $submission->submission_file)), '/');
        if ($path === '' || str_contains($path, '../') || !str_starts_with($path, "project_submissions/{$submission->user_id}/{$submission->project_id}/")) {
            return null;
        }

        foreach ($submission->submissionDiskCandidates() as $candidate) {
            $disk = Storage::disk($candidate);
            if ($disk->exists($path)) {
                return $this->file(
                    $disk,
                    $path,
                    (string) $submission->original_file_name,
                    (string) ($submission->mime_type ?: 'application/octet-stream'),
                    'project-submission'
                );
            }
        }

        return null;
    }

    /** @return array{disk:FilesystemAdapter,path:string,name:string,mime:string}|null */
    public function attachmentForAdmin(
        ProjectSubmission $submission,
        AiInputAttachment $attachment
    ): ?array {
        if ((int) $attachment->user_id !== (int) $submission->user_id) {
            return null;
        }
        $belongsToSubmission = $attachment->owner_type === AiInputAttachment::OWNER_PROJECT_SUBMISSION
            && $attachment->purpose === AiInputAttachment::PURPOSE_PROJECT_SUBMISSION
            && (int) $attachment->owner_id === (int) $submission->id;
        $belongsToThread = $attachment->owner_type === AiInputAttachment::OWNER_PROJECT_FEEDBACK_MESSAGE
            && $attachment->purpose === AiInputAttachment::PURPOSE_PROJECT_FOLLOWUP
            && ProjectFeedbackMessage::query()
                ->whereKey($attachment->owner_id)
                ->whereHas(
                    'thread',
                    fn ($thread) => $thread->where('submission_id', $submission->id)
                )->exists();
        if (!$belongsToSubmission && !$belongsToThread) {
            return null;
        }

        return $this->storedAttachment($attachment, 'project-submission');
    }

    /** @return array{disk:FilesystemAdapter,path:string,name:string,mime:string}|null */
    public function attachment(AiInputAttachment $attachment, int $userId): ?array
    {
        if (!$this->belongsTo($attachment, $userId)) {
            return null;
        }

        return $this->storedAttachment($attachment, 'project-attachment');
    }

    public function belongsTo(AiInputAttachment $attachment, int $userId): bool
    {
        if ($userId <= 0 || (int) $attachment->user_id !== $userId || $attachment->status !== AiInputAttachment::READY) {
            return false;
        }

        return match ($attachment->owner_type) {
            AiInputAttachment::OWNER_COURSE_CHAT_TURN => $attachment->purpose === AiInputAttachment::PURPOSE_COURSE_CHAT
                && CourseChatTurn::query()->whereKey($attachment->owner_id)->where('user_id', $userId)->where('course_id', $attachment->course_id)->exists(),
            AiInputAttachment::OWNER_PROJECT_SUBMISSION => $attachment->purpose === AiInputAttachment::PURPOSE_PROJECT_SUBMISSION
                && $this->submissionMatches($attachment, $userId),
            AiInputAttachment::OWNER_PROJECT_FEEDBACK_MESSAGE => $attachment->purpose === AiInputAttachment::PURPOSE_PROJECT_FOLLOWUP
                && ProjectFeedbackMessage::query()->whereKey($attachment->owner_id)->whereHas(
                    'thread',
                    fn ($query) => $query->where('user_id', $userId)->where('course_id', $attachment->course_id)
                )->exists(),
            default => false,
        };
    }

    private function submissionMatches(AiInputAttachment $attachment, int $userId): bool
    {
        $submission = ProjectSubmission::query()
            ->whereKey($attachment->owner_id)
            ->where('user_id', $userId)
            ->with('project.section')
            ->first();

        return $submission !== null && (
            (int) data_get($submission->evaluation_snapshot, 'course_id', 0) === (int) $attachment->course_id
            || (int) ($submission->project?->section?->course_id ?? 0) === (int) $attachment->course_id
        );
    }

    /** @return array{disk:FilesystemAdapter,path:string,name:string,mime:string}|null */
    private function storedAttachment(AiInputAttachment $attachment, string $fallback): ?array
    {
        if ($attachment->status !== AiInputAttachment::READY) {
            return null;
        }
        $path = ltrim((string) $attachment->storage_path, '/');
        $disk = Storage::disk((string) $attachment->storage_disk);
        if ($path === '' || str_contains($path, '../') || !$disk->exists($path)) {
            return null;
        }

        return $this->file(
            $disk,
            $path,
            (string) $attachment->original_file_name,
            (string) $attachment->mime_type,
            $fallback
        );
    }

    /** @return array{disk:FilesystemAdapter,path:string,name:string,mime:string} */
    private function file(
        FilesystemAdapter $disk,
        string $path,
        string $original,
        string $mime,
        string $fallback
    ): array
    {
        $name = DownloadFilename::safe($original, $fallback, pathinfo($path, PATHINFO_EXTENSION));

        return compact('disk', 'path', 'name', 'mime');
    }
}
