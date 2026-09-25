<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\CourseSection;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Support\DownloadFilename;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use App\Support\UnicodeText;
use App\Support\UploadBudget;

final class ProjectSubmissionService
{
    public function __construct(
        private readonly AiInputAttachmentService $attachments,
        private readonly ProjectSubmissionInputService $inputs,
        private readonly ProjectSubmissionEffortGuard $effort,
        private readonly StoredFileUploadService $uploads,
        private readonly CourseEntitlementService $courseAccess,
        private readonly CourseAccessPlanService $accessPlans,
        private readonly CourseRevisionResolver $revisionResolver,
        private readonly ProjectSubmissionFileRetentionService $fileRetention,
        private readonly ProjectSubmissionEvaluationScheduler $evaluations
    ) {
    }

    /** Resolve a committed POST whose response was lost before mutable admission checks run again. */
    public function replayCommittedSubmission(
        User $user,
        Project $project,
        ?string $text,
        ?array $files,
        string $idempotencyKey
    ): ?ProjectSubmission {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            return null;
        }

        [$text, $files] = $this->inputs->normalize($text, $files);
        $equivalentProjectIds = $this->revisionResolver->equivalentEntityIds(
            Project::class,
            (int) $project->id
        );
        $existing = $this->idempotentSubmission(
            (int) $user->id,
            $equivalentProjectIds,
            $idempotencyKey,
            $this->inputs->fingerprint($text, $files)
        );

        if (!$existing) {
            return null;
        }

        return $this->evaluations->dispatchIfDue($existing);
    }

    public function submit(
        User $user,
        Project $project,
        ?string $text,
        ?array $files,
        string $idempotencyKey,
        array $metadata = [],
        ?UploadBudget $uploadBudget = null
    ): ProjectSubmission {
        $uploadBudget ??= UploadBudget::start((float) config('projects.submission_request_budget_seconds', 16));
        [$text, $files] = $this->inputs->normalize($text, $files);
        if ($text !== null && UnicodeText::graphemeLength($text) > 20000) {
            throw ValidationException::withMessages([
                'submission_text' => ['نص المشروع أطول من الحد المتاح'],
            ]);
        }
        $submissionDisk = (string) config('projects.submission_disk', 'local');
        if ($submissionDisk === '' || !is_array(config("filesystems.disks.{$submissionDisk}"))) {
            throw new \RuntimeException('The configured project submission disk is not available.');
        }

        $this->inputs->assertAllowedFileTypes($project, $files);
        $requestFingerprint = $this->inputs->fingerprint($text, $files);
        $equivalentProjectIds = $this->revisionResolver->equivalentEntityIds(
            Project::class,
            (int) $project->id
        );
        $existing = $this->idempotentSubmission(
            (int) $user->id,
            $equivalentProjectIds,
            $idempotencyKey,
            $requestFingerprint
        );

        if ($existing) {
            return $this->evaluations->dispatchIfDue($existing);
        }

        // A passed project is final, and a pending upload is resumed instead of duplicated.
        // This keeps retries/offline replays from ever locking the learner again.
        $activeSubmission = ProjectSubmission::query()
            ->where('user_id', $user->id)
            ->whereIn('project_id', $equivalentProjectIds)
            ->whereIn('review_status', [
                ProjectSubmission::STATUS_PENDING,
                ProjectSubmission::STATUS_PASSED,
            ])
            ->latest('id')
            ->first();
        if ($activeSubmission) {
            return $this->evaluations->dispatchIfDue($activeSubmission);
        }

        $this->inputs->assertReviewCapacity($project, $text, $files);

        $effortStatus = $this->effort->assess($text, $files);
        $storedPaths = [];
        $fileDescriptors = [];

        // Stage immutable, request-scoped object keys before taking any user
        // or database lock. The cleanup ledger commits before the first byte;
        // a process death or a losing concurrent request is therefore harmless.
        foreach ($files as $index => $file) {
            $sha = hash_file('sha256', $file->getRealPath());
            if (!is_string($sha) || $sha === '') {
                throw new \RuntimeException('The project attachment could not be fingerprinted.');
            }
            $storedPath = $this->uploads->storeTrackedUpload(
                $file,
                "project_submissions/{$user->id}/{$project->id}",
                $submissionDisk,
                60,
                implode('|', [
                    'project-submission', $user->id, $project->id,
                    strtolower($idempotencyKey), $index, $sha,
                ]),
                $uploadBudget
            );
            $storedPaths[] = $storedPath;
            $fileDescriptors[] = [
                'path' => $storedPath,
                'name' => DownloadFilename::safe(
                    $file->getClientOriginalName(),
                    'project-submission',
                    $this->attachments->canonicalExtension(
                        (string) $this->attachments->canonicalMime($file)
                    )
                ),
                'mime_type' => (string) $this->attachments->canonicalMime($file),
                'size_bytes' => (int) $file->getSize(),
                'sha256' => $sha,
                'storage_disk' => $submissionDisk,
            ];
        }

        $projectCourse = $project->section?->course;
        $admissionCourseId = $projectCourse
            ? (int) $this->revisionResolver->canonicalFor($projectCourse)->id
            : null;
        try {
            $submission = DB::transaction(function () use (
                $user,
                $project,
                $text,
                $files,
                $storedPaths,
                $idempotencyKey,
                $metadata,
                $effortStatus,
                $requestFingerprint,
                $submissionDisk,
                $fileDescriptors,
                $equivalentProjectIds,
                $admissionCourseId
            ): ProjectSubmission {
                // Different client retry keys are still serialized per learner,
                // then share the publication boundary just like course purchases.
                // Different learners remain concurrent; an exclusive publish
                // cannot retire this graph between admission and receipt commit.
                $activeUser = User::query()
                    ->whereKey($user->id)
                    ->where('active', true)
                    ->lockForUpdate()
                    ->first();
                if (!$activeUser) {
                    throw new AuthorizationException(
                        'The learner account is no longer active.'
                    );
                }
                $admissionCourse = $admissionCourseId
                    ? Course::query()->sharedLock()->find($admissionCourseId)
                    : null;
                if (!$admissionCourse?->isPublishedForLearning()) {
                    throw new AuthorizationException(
                        'The course is no longer available for project submissions.'
                    );
                }
                $projectSnapshot = Project::query()->findOrFail($project->id);

                $existing = ProjectSubmission::query()
                    ->where('user_id', $user->id)
                    ->whereIn('project_id', $equivalentProjectIds)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    $this->assertIdempotentReplay($existing, $requestFingerprint);
                    return $existing;
                }

                $activeSubmission = ProjectSubmission::query()
                    ->where('user_id', $user->id)
                    ->whereIn('project_id', $equivalentProjectIds)
                    ->whereIn('review_status', [
                        ProjectSubmission::STATUS_PENDING,
                        ProjectSubmission::STATUS_PASSED,
                    ])
                    ->latest('id')
                    ->first();
                if ($activeSubmission) {
                    return $activeSubmission;
                }

                $primaryPath = $storedPaths[0] ?? null;
                $primaryDescriptor = $fileDescriptors[0] ?? null;
                $projectSection = CourseSection::query()
                    ->where('sectionable_type', Project::class)
                    ->where('sectionable_id', $projectSnapshot->id)
                    ->with('course:id,name_ar,name_en')
                    ->first();
                if (!$projectSection || (int) $projectSection->course_id !== (int) $admissionCourse->id) {
                    throw new AuthorizationException(
                        'The project is no longer part of the published course.'
                    );
                }
                $selectedEnrollment = $this->courseAccess->activeProjectEnrollmentFor(
                    (int) $user->id,
                    (int) $projectSection->course_id
                );
                $enrollment = $selectedEnrollment
                    ? CourseEnrollment::query()
                        ->whereKey($selectedEnrollment->id)
                        ->where('user_id', $user->id)
                        ->lockForUpdate()
                        ->first()
                    : null;
                if (
                    !$enrollment?->isActive()
                    || !$this->courseAccess->hasLearningAccess(
                        (int) $user->id,
                        (int) $projectSection->course_id
                    )
                ) {
                    throw new AuthorizationException(
                        'The learner no longer has an active enrollment for this project.'
                    );
                }
                $accessTerms = $this->accessPlans->termsForEnrollment($enrollment);
                $evaluationSnapshot = ProjectSubmissionEvaluationSnapshot::capture(
                    $projectSnapshot,
                    $projectSection,
                    $enrollment,
                    $accessTerms
                );

                $isInvalid = $effortStatus === ProjectSubmission::EFFORT_INVALID;
                $reviewStatus = $isInvalid
                    ? ProjectSubmission::STATUS_NEEDS_RESUBMISSION
                    : ProjectSubmission::STATUS_PENDING;
                $feedback = $isInvalid
                    ? "المحاولة غير واضحة بما يكفي للمراجعة\nارفع صورة أو ملفًا يوضح ما نفذته"
                    : null;
                $submission = ProjectSubmission::create([
                    'public_id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'project_id' => $project->id,
                    'idempotency_key' => $idempotencyKey,
                    'submission_text' => $text,
                    'submission_file' => $primaryPath,
                    'original_file_name' => $primaryDescriptor['name'] ?? null,
                    'mime_type' => $primaryDescriptor['mime_type'] ?? null,
                    'file_size' => $primaryDescriptor['size_bytes'] ?? null,
                    'submission_metadata' => array_merge($metadata, [
                        'request_fingerprint' => $requestFingerprint,
                        'upload_session_id' => $idempotencyKey,
                        'object_key' => $primaryPath,
                        'checksum_sha256' => $primaryDescriptor
                            ? $primaryDescriptor['sha256']
                            : hash('sha256', trim((string) $text)),
                        'files' => $fileDescriptors,
                        'upload_finalized_at' => now()->toIso8601String(),
                        // Persist the exact private disk with the row. Changing
                        // PROJECT_SUBMISSION_DISK later must not orphan uploads
                        // created by an older web or queue node.
                        'storage_disk' => $submissionDisk,
                        'evaluation' => $isInvalid ? null : [
                            'version' => 1,
                            'status' => 'queued',
                            'request_id' => (string) Str::uuid(),
                            'queued_at' => now()->toIso8601String(),
                            'retry_count' => 0,
                            'retry_safe' => false,
                        ],
                    ]),
                    'evaluation_snapshot' => $evaluationSnapshot,
                    'effort_status' => $effortStatus,
                    // Nonblank input still needs a relevance decision before progression.
                    'review_status' => $reviewStatus,
                    'review_source' => $isInvalid ? 'effort_guard' : null,
                    'score' => $isInvalid ? 0 : null,
                    'feedback' => $feedback,
                    'submitted_at' => now(),
                    // Retained as a durable recovery deadline, never an automatic pass.
                    'auto_pass_at' => $isInvalid ? null : now(),
                    'reviewed_at' => $isInvalid ? now() : null,
                ]);

                if ($isInvalid) {
                    $submissionMetadata = (array) $submission->submission_metadata;
                    $submissionMetadata['assessment_type'] = 'effort_guard';
                    $submissionMetadata['skill_verified'] = false;
                    $submissionMetadata['progression_credit'] = false;
                    $submission->forceFill([
                        'submission_metadata' => $submissionMetadata,
                    ])->save();
                }

                if ($files !== []) {
                    $courseId = $projectSection?->course_id;
                    $course = $courseId ? Course::query()->find($courseId) : null;
                    if ($course) {
                        foreach ((array) data_get($submission->submission_metadata, 'files', []) as $index => $stored) {
                            $this->attachments->registerStored(
                                $user, $course, (string) $stored['path'], $submissionDisk,
                                (string) $stored['name'], (string) $stored['mime_type'],
                                (int) $stored['size_bytes'], (string) $stored['sha256'],
                                $this->deterministicUploadId(
                                    implode('|', [
                                        'project-ai-input', $user->id, $projectSnapshot->id,
                                        strtolower($idempotencyKey), $index, (string) $stored['sha256'],
                                    ])
                                ),
                                (int) $submission->id
                            );
                        }
                    }
                }

                return $submission;
            });

            $result = $this->evaluations->dispatchIfDue($submission);
            $this->fileRetention->purgeIfEligible($result);
            return $result->fresh();
        } catch (QueryException $exception) {
            $existing = ProjectSubmission::query()
                ->where('user_id', $user->id)
                ->whereIn('project_id', $equivalentProjectIds)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                $this->assertIdempotentReplay($existing, $requestFingerprint);
                return $this->evaluations->dispatchIfDue($existing);
            }

            throw $exception;
        }
    }

    private function assertIdempotentReplay(
        ProjectSubmission $submission,
        string $fingerprint
    ): void {
        $storedFingerprint = (string) data_get(
            $submission->submission_metadata,
            'request_fingerprint',
            ''
        );
        if ($storedFingerprint === '' || !hash_equals($storedFingerprint, $fingerprint)) {
            throw new \UnexpectedValueException(
                'Project submission idempotency key was reused for different content.'
            );
        }
    }

    /** @param iterable<int> $equivalentProjectIds */
    private function idempotentSubmission(
        int $userId,
        iterable $equivalentProjectIds,
        string $idempotencyKey,
        string $requestFingerprint
    ): ?ProjectSubmission {
        $existing = ProjectSubmission::query()
            ->where('user_id', $userId)
            ->whereIn('project_id', $equivalentProjectIds)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            $this->assertIdempotentReplay($existing, $requestFingerprint);
        }

        return $existing;
    }

    private function deterministicUploadId(string $identity): string
    {
        $hex = substr(hash('sha256', $identity), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }
}
