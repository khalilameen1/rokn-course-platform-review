<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProjectSubmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Erases uploaded personal content and returns paths for the account's cleanup ledger.
 * The caller holds the learner lock and records returned paths before committing
 * identity erasure. This owner never dispatches jobs or deletes physical bytes.
 */
final class AccountUploadedContentErasureService
{
    /**
     * Capture learning/profile uploads while retaining submission review evidence.
     *
     * @return list<array{disk: string, path: string}>
     */
    public function eraseLearningFilesWithinDeletion(User $user): array
    {
        $this->assertDeletionTransaction();
        $userId = (int) $user->id;
        $storedFiles = [];
        $profileImage = trim((string) $user->getRawOriginal('profile_image'));

        if ($profileImage !== '' && !filter_var($profileImage, FILTER_VALIDATE_URL)) {
            $storedFiles[] = ['disk' => 'public', 'path' => ltrim($profileImage, '/')];
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
            if (Schema::hasColumn('ai_input_attachments', 'user_id')) {
                DB::table('ai_input_attachments')->where('user_id', $userId)->delete();
            }
        }

        if (Schema::hasTable('project_submissions')) {
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

        return $storedFiles;
    }

    /**
     * Remove support reports only after capturing their attachment destinations.
     *
     * @return list<array{disk: string, path: string}>
     */
    public function eraseSupportFilesWithinDeletion(int $userId): array
    {
        $this->assertDeletionTransaction();
        $storedFiles = [];
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

        return $storedFiles;
    }

    /**
     * Replace the legacy model hook with transactional reference-only erasure.
     *
     * @return list<array{disk: string, path: string}>
     */
    public function eraseLegacyPhotosWithinDeletion(int $userId): array
    {
        $this->assertDeletionTransaction();
        $storedFiles = [];
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
            foreach ($legacyPhotoPaths as $path) {
                $storedFiles[] = ['disk' => 'public', 'path' => $path];
            }
            $legacyPhotoQuery->delete();
        }

        return $storedFiles;
    }

    private function assertDeletionTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Uploaded content erasure must share the account-deletion transaction.');
        }
    }
}
