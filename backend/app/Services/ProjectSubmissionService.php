<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\GenerateProjectFeedback;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\CourseSection;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Support\DownloadFilename;
use App\Support\DurableJobDispatch;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use App\Support\UnicodeText;
use ZipArchive;

final class ProjectSubmissionService
{
    public function __construct(
        private readonly AiInputAttachmentService $attachments,
        private readonly StoredFileDeletionService $storedFiles,
        private readonly InternalSignalService $internalSignals,
        private readonly CourseChatAccessService $courseAccess,
        private readonly CourseAccessPlanService $accessPlans,
        private readonly CourseStagedAuthoringService $stagedAuthoring,
        private readonly CourseRevisionLearnerReadService $revisionReads,
        private readonly ProjectSubmissionFileRetentionService $fileRetention,
        private readonly CourseCompletionService $courseCompletion
    ) {
    }

    public function submit(
        User $user,
        Project $project,
        ?string $text,
        ?array $files,
        string $idempotencyKey,
        array $metadata = []
    ): ProjectSubmission {
        $text = $text === null ? null : UnicodeText::clean($text);
        if ($text === '') $text = null;
        if ($text !== null && UnicodeText::graphemeLength($text) > 20000) {
            throw ValidationException::withMessages([
                'submission_text' => ['نص المشروع أطول من الحد المتاح'],
            ]);
        }
        $submissionDisk = (string) config('projects.submission_disk', 'local');
        if ($submissionDisk === '' || !is_array(config("filesystems.disks.{$submissionDisk}"))) {
            throw new \RuntimeException('The configured project submission disk is not available.');
        }

        $files ??= [];
        $files = array_values(array_filter($files, static fn ($file): bool => $file instanceof UploadedFile));
        $allowedMimes = $project->submission_allowed_mime_types === null
            ? array_map('strtolower', (array) config('projects.allowed_mime_types', []))
            : array_map('strtolower', (array) $project->submission_allowed_mime_types);
        foreach ($files as $file) {
            $canonicalMime = $this->attachments->canonicalMime($file);
            if ($canonicalMime === null || !in_array($canonicalMime, $allowedMimes, true)) {
                throw ValidationException::withMessages([
                    'submission_files' => ['أحد الملفات بصيغة غير متاحة لهذا المشروع'],
                ]);
            }
        }
        $requestFingerprint = $this->requestFingerprint($text, $files);
        $equivalentProjectIds = $this->stagedAuthoring->equivalentEntityIds(
            Project::class,
            (int) $project->id
        );
        $existing = ProjectSubmission::query()
            ->where('user_id', $user->id)
            ->whereIn('project_id', $equivalentProjectIds)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            $this->assertIdempotentReplay($existing, $requestFingerprint);
            return $this->finalizeIfDue($existing);
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
            return $this->finalizeIfDue($activeSubmission);
        }

        $effortStatus = $this->detectEffort($text, $files);
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
            $storedPath = $this->storedFiles->storeTrackedUpload(
                $file,
                "project_submissions/{$user->id}/{$project->id}",
                $submissionDisk,
                60,
                implode('|', [
                    'project-submission', $user->id, $project->id,
                    strtolower($idempotencyKey), $index, $sha,
                ])
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
                $equivalentProjectIds
            ): ProjectSubmission {
                // Different client retry keys are still serialized per learner,
                // preventing two simultaneous uploads for the same project.
                // Project and CourseSection are published catalog definitions;
                // locking either one would serialize every learner submitting
                // the same assignment. The mutable enrollment/submission state
                // below remains locked at its owning learner boundary.
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
                if (!$projectSection) {
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
                    ]),
                    'evaluation_snapshot' => $evaluationSnapshot,
                    'effort_status' => $effortStatus,
                    // Empty/black/solid attempts are the only immediate stop.
                    // A sincere attempt keeps the short reviewing state and then passes.
                    'review_status' => $reviewStatus,
                    'review_source' => $isInvalid ? 'effort_guard' : null,
                    'score' => $isInvalid ? 0 : null,
                    'feedback' => $feedback,
                    'submitted_at' => now(),
                    'auto_pass_at' => $isInvalid
                        ? null
                        : now()->addSeconds(max(1, (int) (
                            $projectSnapshot->fallback_review_delay_seconds
                            ?? config('projects.fallback_review_delay_seconds', 8)
                        ))),
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

            $result = $this->finalizeIfDue($submission);
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
                return $this->finalizeIfDue($existing);
            }

            throw $exception;
        }
    }

    public function finalizeIfDue(ProjectSubmission $submission): ProjectSubmission
    {
        if (
            $submission->review_status !== ProjectSubmission::STATUS_PENDING
            || !$submission->auto_pass_at
            || $submission->auto_pass_at->isFuture()
        ) {
            return $submission;
        }

        $result = DB::transaction(function () use ($submission): ProjectSubmission {
            // Account deletion owns the learner row before scrubbing this
            // aggregate. Taking the same owner lock first prevents a delayed
            // fallback job from recreating review/progress data afterwards.
            $learner = User::query()
                ->whereKey($submission->user_id)
                ->lockForUpdate()
                ->first();
            if (!$learner) {
                return $submission->fresh();
            }
            $locked = ProjectSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            if ($locked->review_status !== ProjectSubmission::STATUS_PENDING) {
                return $locked;
            }

            $wasAlreadyPassed = $this->hasPassedProject(
                (int) $locked->user_id,
                (int) $locked->project_id
            );
            $passed = $wasAlreadyPassed
                || $locked->effort_status !== ProjectSubmission::EFFORT_INVALID;
            $feedback = $passed
                ? 'استلمنا محاولة واضحة وفتحنا لك المقطع التالي\nهذا قبول للاستكمال وليس تقييمًا للعمل'
                : "المحاولة غير واضحة بما يكفي للمراجعة\nارفع صورة أو ملفًا يوضح ما نفذته";

            return $this->applyReviewOutcome(
                $locked,
                $passed,
                'graceful_fallback',
                $feedback
            );
        });

        if (
            $result->review_status === ProjectSubmission::STATUS_PASSED
            && $this->submissionIncludesProjectReport($result)
        ) {
            // Feedback is a paid enhancement, never a gate. Queue/provider
            // failures cannot revoke the already granted progression.
            $this->queueFeedback((int) $result->id);
        } else {
            $this->fileRetention->purgeIfEligible($result);
        }

        return $result;
    }

    public function reviewByStaff(
        ProjectSubmission $submission,
        User $reviewer,
        bool $passed,
        ?string $feedback = null
    ): ProjectSubmission {
        $reviewerRole = Str::lower((string) $reviewer->role);
        if (!(bool) $reviewer->active || !in_array($reviewerRole, ['admin', 'moderator'], true)) {
            throw new AuthorizationException('Only an active dashboard reviewer can review project submissions.');
        }

        $reviewed = DB::transaction(function () use ($submission, $reviewer, $passed, $feedback): ProjectSubmission {
            // Serialize a human decision with account deletion at the same
            // aggregate-owner boundary used by learner submission. A form
            // left open for a deleted account must not restore scrubbed text,
            // progress or feedback records.
            $learner = User::query()
                ->whereKey($submission->user_id)
                ->lockForUpdate()
                ->first();
            if (!$learner) {
                throw ValidationException::withMessages([
                    'submission' => ['تم حذف حساب الطالب، لذلك لم يُسجل قرار جديد.'],
                ]);
            }
            $locked = ProjectSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            $latestAttemptId = ProjectSubmission::query()
                ->where('user_id', $locked->user_id)
                ->where('project_id', $locked->project_id)
                ->max('id');
            if ((int) $latestAttemptId !== (int) $locked->id) {
                throw ValidationException::withMessages([
                    'submission' => ['هذه محاولة قديمة. راجع أحدث محاولة للطالب قبل تسجيل القرار.'],
                ]);
            }
            $isGracefulFallback = $locked->review_status === ProjectSubmission::STATUS_PASSED
                && $locked->review_source === 'graceful_fallback';
            if (
                $locked->review_status !== ProjectSubmission::STATUS_PENDING
                && !$isGracefulFallback
            ) {
                throw ValidationException::withMessages([
                    'submission' => ['تمت مراجعة هذه المحاولة بالفعل، لذلك لم يتغير القرار المسجل.'],
                ]);
            }

            $wasAlreadyPassed = $this->hasPassedProject(
                (int) $locked->user_id,
                (int) $locked->project_id
            );
            if (!$passed && $wasAlreadyPassed) {
                throw ValidationException::withMessages([
                    'submission' => ['لا يمكن سحب حق الطالب في الاستكمال بعد قبوله تلقائيًا. يمكن اعتماد جودة العمل يدويًا عند القبول.'],
                ]);
            }

            $reviewFeedback = trim((string) $feedback);
            if ($reviewFeedback === '') {
                $reviewFeedback = $passed
                    ? 'راجع فريق ركن المحاولة وقبلها'
                    : 'راجع فريق ركن المحاولة وطلب إعادة إرسالها';
            }

            return $this->applyReviewOutcome(
                $locked,
                $passed,
                'admin_manual',
                $reviewFeedback,
                $reviewer
            );
        });

        if (
            $reviewed->review_status === ProjectSubmission::STATUS_PASSED
            && $this->submissionIncludesProjectReport($reviewed)
        ) {
            $this->queueFeedback((int) $reviewed->id);
        } else {
            $this->fileRetention->purgeIfEligible($reviewed);
        }

        return $reviewed;
    }

    private function applyReviewOutcome(
        ProjectSubmission $locked,
        bool $passed,
        string $source,
        string $feedback,
        ?User $reviewer = null
    ): ProjectSubmission {
        $status = $passed
            ? ProjectSubmission::STATUS_PASSED
            : ProjectSubmission::STATUS_NEEDS_RESUBMISSION;
        $isParticipationAcceptance = $passed && $source === 'graceful_fallback';
        // The forgiving fallback grants progression only. A numeric score is
        // evidence of assessment, so it is reserved for a human review.
        $score = $passed ? ($isParticipationAcceptance ? null : 100) : 0;
        $reviewedAt = now();
        $metadata = is_array($locked->submission_metadata)
            ? $locked->submission_metadata
            : [];
        $metadata['assessment_type'] = $isParticipationAcceptance
            ? 'participation'
            : ($source === 'admin_manual' ? 'human_review' : 'effort_guard');
        $metadata['skill_verified'] = $passed && $source === 'admin_manual';
        $metadata['progression_credit'] = $passed;
        if (
            $passed
            && $this->submissionIncludesProjectReport($locked)
            && data_get($metadata, 'ai_feedback.status') !== 'ready'
        ) {
            // Persist intent before the queue dispatch so a lost enqueue can
            // be recovered. The job terminally classifies pass-only plans.
            $metadata['ai_feedback'] = [
                'status' => 'queued',
                'queued_at' => $reviewedAt->toIso8601String(),
            ];
        }

        $locked->update([
            'review_status' => $status,
            'review_source' => $source,
            'score' => $score,
            'feedback' => $feedback,
            'reviewed_at' => $reviewedAt,
            'reviewed_by' => $reviewer?->id,
            'submission_metadata' => $metadata,
        ]);

        $currentProjectId = $this->stagedAuthoring->currentEntityId(
            Project::class,
            (int) $locked->project_id
        );

        $projectSection = CourseSection::query()
            ->where('sectionable_type', Project::class)
            ->where('sectionable_id', $currentProjectId ?: $locked->project_id)
            ->first();
        $this->internalSignals->record(
            'project.review.notification',
            "submission:{$locked->public_id}:status:{$status}",
            [
                'submission_id' => (int) $locked->id,
                'user_id' => (int) $locked->user_id,
                'project_id' => (int) ($currentProjectId ?: $locked->project_id),
                'course_id' => (int) (
                    $projectSection?->course_id
                    ?? data_get($locked->evaluation_snapshot, 'course_id', 0)
                ),
                'status' => $status,
            ],
            ProjectSubmission::class,
            (int) $locked->id
        );

        if (!$passed) {
            return $locked->fresh();
        }

        $this->internalSignals->record(
            'project.passed.first_reward',
            "user:{$locked->user_id}:project:{$locked->project_id}",
            [
                'user_id' => (int) $locked->user_id,
                'project_id' => (int) $locked->project_id,
            ],
            ProjectSubmission::class,
            (int) $locked->id
        );

        if (!$projectSection) {
            return $locked->fresh();
        }

        $this->courseCompletion->recordPassedProject(
            (int) $locked->user_id,
            $projectSection
        );

        return $locked->fresh();
    }

    private function queueFeedback(int $submissionId): void
    {
        try {
            DurableJobDispatch::afterCommit(new GenerateProjectFeedback($submissionId));
        } catch (\Throwable $exception) {
            // The committed queued marker is the durable handoff. Recovery
            // will enqueue it when the broker returns, so a passed project
            // must not look failed merely because that first enqueue failed.
            report($exception);
        }
    }

    public function finalizeDue(int $limit = 100): int
    {
        $count = 0;
        ProjectSubmission::query()
            ->where('review_status', ProjectSubmission::STATUS_PENDING)
            ->whereNotNull('auto_pass_at')
            ->where('auto_pass_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (ProjectSubmission $submission) use (&$count): void {
                $this->finalizeIfDue($submission);
                $count++;
            });

        return $count;
    }

    private function detectEffort(?string $text, array $files): string
    {
        $plainText = trim((string) $text);
        if ($files !== []) {
            foreach ($files as $file) {
                if ((int) $file->getSize() < (int) config('projects.minimum_file_bytes', 512)) {
                    continue;
                }
                if ($this->isMeaningfulFile($file)) {
                    return ProjectSubmission::EFFORT_VALID;
                }
            }
            if ($plainText === '') {
                return ProjectSubmission::EFFORT_INVALID;
            }
        }

        return mb_strlen($plainText) >= (int) config('projects.minimum_text_length', 10)
            && !$this->isObviousGaming($plainText)
            ? ProjectSubmission::EFFORT_VALID
            : ProjectSubmission::EFFORT_INVALID;
    }

    private function isMeaningfulFile(UploadedFile $file): bool
    {
        $mime = (string) $this->attachments->canonicalMime($file);
        if (str_starts_with($mime, 'image/')) {
            return !$this->isBlankImage($file);
        }

        $path = $file->getRealPath();
        if (!is_string($path) || $path === '' || !is_readable($path)) {
            return false;
        }

        if ($mime === 'text/plain') {
            $body = file_get_contents($path, false, null, 0, 65536);
            if (!is_string($body)) return false;
            $body = UnicodeText::clean($body);

            return UnicodeText::graphemeLength($body) >= (int) config('projects.minimum_text_length', 10)
                && !$this->isObviousGaming($body);
        }

        if ($mime === 'application/pdf') {
            $handle = fopen($path, 'rb');
            if ($handle === false) return false;
            try {
                $head = fread($handle, 262144);
                if (!is_string($head) || !str_starts_with($head, '%PDF-')) return false;
                $size = max(0, (int) filesize($path));
                if ($size > 16384) fseek($handle, -16384, SEEK_END);
                else rewind($handle);
                $tail = fread($handle, 16384);

                if (!is_string($tail) || preg_match('/%%EOF\s*\z/s', $tail) !== 1) {
                    return false;
                }

                // Page dictionaries may be stored in compressed object
                // streams, so their text is not a reliable validity test.
                // Accept the cheap visible-page case, otherwise verify the
                // required cross-reference pointer and the object it names.
                if (preg_match('/\/Type\s*\/Page\b/', $head) === 1) {
                    return true;
                }
                if (preg_match('/startxref\s+(\d+)\s+%%EOF\s*\z/s', $tail, $match) !== 1) {
                    return false;
                }

                $xrefOffset = (int) $match[1];
                if ($xrefOffset < 1 || $xrefOffset >= $size
                    || fseek($handle, $xrefOffset, SEEK_SET) !== 0) {
                    return false;
                }
                $xref = fread($handle, 2048);
                if (!is_string($xref)) {
                    return false;
                }
                $xref = ltrim($xref);

                return str_starts_with($xref, 'xref')
                    || (
                        preg_match('/^\d+\s+\d+\s+obj\b/', $xref) === 1
                        && preg_match('/\/Type\s*\/XRef\b/', $xref) === 1
                    );
            } finally {
                fclose($handle);
            }
        }

        if (in_array($mime, [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ], true)) {
            if (!class_exists(ZipArchive::class)) return false;
            $archive = new ZipArchive();
            if ($archive->open($path) !== true) return false;
            try {
                $mainEntry = $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                    ? 'word/document.xml'
                    : 'ppt/presentation.xml';
                if ($archive->locateName('[Content_Types].xml') === false
                    || $archive->locateName($mainEntry) === false) {
                    return false;
                }

                for ($index = 0; $index < $archive->numFiles; $index++) {
                    $name = (string) $archive->getNameIndex($index);
                    if (preg_match('#^(?:word|ppt)/media/[^/]+$#', $name) === 1) {
                        $stat = $archive->statIndex($index);
                        if ((int) ($stat['size'] ?? 0) > 0) return true;
                    }
                    if (preg_match('#^(?:word/document|ppt/slides/slide[0-9]+)\.xml$#', $name) !== 1) {
                        continue;
                    }
                    $xml = $archive->getFromIndex($index);
                    if (is_string($xml)
                        && preg_match('/<(?:w:t|a:t)(?:\s[^>]*)?>\s*[^<\s][^<]*</u', $xml) === 1) {
                        return true;
                    }
                }

                return false;
            } finally {
                $archive->close();
            }
        }

        return false;
    }

    private function submissionIncludesProjectReport(ProjectSubmission $submission): bool
    {
        $snapshot = ProjectSubmissionEvaluationSnapshot::fromSubmission($submission);
        $terms = $snapshot ? data_get($snapshot, 'access.terms') : null;

        if (!is_array($terms)) {
            return false;
        }

        $courseId = (int) data_get($snapshot, 'course_id', 0);
        $enrollmentId = (int) data_get($snapshot, 'access.enrollment_id', 0);
        if ($courseId <= 0 || $enrollmentId <= 0) {
            return false;
        }

        $enrollment = $this->courseAccess->activeCapturedEnrollmentFor(
            (int) $submission->user_id,
            $courseId,
            $enrollmentId
        );

        return $enrollment !== null
            && $this->courseAccess->enrollmentAllowsVariableCostFeatures($enrollment)
            && (bool) $this->accessPlans->publicPayloadFromTerms($terms)['project_report_enabled'];
    }

    private function isObviousGaming(string $text): bool
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text)));
        if ($normalized === '') return true;

        $compact = str_replace(' ', '', $normalized);
        if (preg_match('/^(.)\1{5,}$/u', $compact)) return true;
        if (preg_match('/^(?:asdf|qwer|zxcv|hjkl|1234|abcd)+$/iu', $compact)) return true;

        $words = array_values(array_filter(preg_split('/\s+/u', $normalized) ?: []));
        return count($words) >= 3 && count(array_unique($words)) === 1;
    }

    private function requestFingerprint(?string $text, array $files): string
    {
        $fileFacts = [];
        foreach ($files as $file) {
            $fileHash = hash_file('sha256', $file->getRealPath());
            if (!is_string($fileHash)) {
                throw new \RuntimeException('Unable to fingerprint the project attachment.');
            }
            $fileFacts[] = [
                'sha256' => $fileHash,
                'size' => (int) $file->getSize(),
                'mime_type' => (string) $this->attachments->canonicalMime($file),
            ];
        }

        return hash('sha256', json_encode([
            'text' => trim((string) $text),
            'files' => $fileFacts,
        ], JSON_THROW_ON_ERROR));
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

    private function hasPassedProject(int $userId, int $projectId): bool
    {
        $currentProjectId = $this->stagedAuthoring
            ->currentLearnerEntityMap(Project::class, [$projectId])[$projectId] ?? $projectId;

        return $this->revisionReads
            ->passedProjectIds($userId, [$currentProjectId])
            ->contains($currentProjectId);
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

    private function isBlankImage(UploadedFile $file): bool
    {
        // Missing image tooling is treated forgivingly, never as a student failure.
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }

        // Never decode an unexpectedly large image into PHP memory. Upload
        // validation limits the file bytes, while this guard also blocks tiny
        // compressed images with pathological dimensions (decompression bombs).
        $inspectionBytes = max(1, (int) config('projects.image_inspection_max_bytes', 8388608));
        if ((int) $file->getSize() > $inspectionBytes) {
            return false;
        }

        $dimensions = @getimagesize($file->getRealPath());
        if ($dimensions === false) {
            return true;
        }
        $width = max(0, (int) ($dimensions[0] ?? 0));
        $height = max(0, (int) ($dimensions[1] ?? 0));
        $maximumPixels = max(1, (int) config('projects.image_inspection_max_pixels', 12000000));
        if ($width < 2 || $height < 2) {
            return true;
        }
        if ($width > intdiv($maximumPixels, $height)) {
            return false;
        }

        $contents = @file_get_contents($file->getRealPath());
        $image = $contents !== false ? @imagecreatefromstring($contents) : false;
        if ($image === false) {
            return true;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 2 || $height < 2) {
            imagedestroy($image);
            return true;
        }

        $samples = 0;
        $dark = 0;
        $white = 0;
        $minimumLuminance = 255;
        $maximumLuminance = 0;
        $stepX = max(1, (int) floor($width / 20));
        $stepY = max(1, (int) floor($height / 20));
        $threshold = (int) config('projects.dark_image_threshold', 12);
        $whiteThreshold = (int) config('projects.white_image_threshold', 248);

        for ($x = 0; $x < $width; $x += $stepX) {
            for ($y = 0; $y < $height; $y += $stepY) {
                $rgb = imagecolorat($image, $x, $y);
                $red = ($rgb >> 16) & 0xFF;
                $green = ($rgb >> 8) & 0xFF;
                $blue = $rgb & 0xFF;
                $luminance = (int) round(($red + $green + $blue) / 3);
                $samples++;
                $minimumLuminance = min($minimumLuminance, $luminance);
                $maximumLuminance = max($maximumLuminance, $luminance);
                if (max($red, $green, $blue) <= $threshold) {
                    $dark++;
                }
                if (min($red, $green, $blue) >= $whiteThreshold) {
                    $white++;
                }
            }
        }

        imagedestroy($image);

        if ($samples === 0) {
            return true;
        }

        return ($dark / $samples) >= (float) config('projects.dark_image_ratio', 0.97)
            || ($white / $samples) >= (float) config('projects.white_image_ratio', 0.985)
            || ($maximumLuminance - $minimumLuminance) <= (int) config('projects.solid_image_luminance_range', 3);
    }
}
