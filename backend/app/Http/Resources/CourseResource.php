<?php

namespace App\Http\Resources;

use App\Services\BunnyService;
use App\Models\CourseRating;
use Illuminate\Support\Collection;
use App\Services\CourseAttachmentService;
use App\Services\CoursePresentationService;
use App\Services\CourseAccessPlanService;
use App\Services\ProjectSubmissionPresenter;
use App\Services\CourseRatingEligibilityService;
use App\Services\CourseRevisionLearnerReadService;
use App\Models\CourseEnrollment;
use App\Models\User;

class CourseResource extends BaseCourseResource
{
    private ?BunnyService $bunnyService = null;
    private array $fullSectionContentCache = [];
    private array $sectionLockCache = [];
    private Collection $sectionAccessStates;
    private Collection $projectSubmissions;
    private string $projectFeedbackLevel = 'pass_only';
    private array $projectFeedbackContract = [
        'project_report_enabled' => false,
        'project_thread_reply_enabled' => false,
        'project_message_limit' => 0,
        'project_token_budget' => 0,
    ];
    private bool $projectReplyRiskAllowed = false;
    private Collection $resolvedCompletedSectionIds;
    private array $resolvedEntitlement;
    private ?CourseEnrollment $resolvedEnrollment;
    private User $resourceUser;
    private ?User $dashboardPreviewUser = null;

    /** Reuse the access/progress work already performed by the details query. */
    public function withLearningContext(
        User $user,
        Collection $completedSectionIds,
        array $entitlement,
        ?CourseEnrollment $enrollment
    ): static {
        $this->resourceUser = $user;
        $this->resolvedCompletedSectionIds = $completedSectionIds;
        $this->resolvedEntitlement = $entitlement;
        $this->resolvedEnrollment = $enrollment;

        return $this;
    }

    /**
     * Render the learner contract for an authenticated dashboard preview.
     *
     * This context exists only for this resource instance. It deliberately
     * starts with no learner progress and never creates an enrollment or
     * changes the course publication state.
     *
     * @param array<string, mixed> $planContract
     */
    public function withDashboardPreviewContext(User $actor, array $planContract): static
    {
        $this->dashboardPreviewUser = $actor;
        $this->projectFeedbackContract = $planContract;
        $this->projectReplyRiskAllowed = (bool) (
            $planContract['project_thread_reply_enabled'] ?? false
        );
        $this->projectFeedbackLevel = (string) (
            $planContract['project_feedback_level'] ?? 'pass_only'
        );

        return $this;
    }

    /**
     * Transform the resource into an array.
     * Full course resource with sensitive data and section lock status for authorized users
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $baseData = parent::toArray($request);

        $user = $this->dashboardPreviewUser ?? $this->resourceUser;
        // A dashboard actor supplies identity only so the entitled resource
        // follows the same branch as the app. Their own progress, attempts and
        // submissions must never leak into a fresh-student preview.
        $learnerStateUser = $this->dashboardPreviewUser ? null : $user;
        $sections = $this->relationLoaded('modules')
            ? app(\App\Services\CourseSectionSequenceService::class)
                ->fromModules($this->modules)
            : collect();
        $completedSectionIds = $this->resolvedCompletedSectionIds;
        $projectIds = $sections
            ->filter(fn ($section) => $section->getSectionType() === 'project')
            ->pluck('sectionable_id')
            ->filter()
            ->unique()
            ->values();
        $revisionReads = app(CourseRevisionLearnerReadService::class);
        $this->projectSubmissions = ($learnerStateUser && $projectIds->isNotEmpty())
            ? $revisionReads->projectSubmissions(
                (int) $learnerStateUser->id,
                $projectIds,
                ['aiInputAttachments', 'feedbackThread.enrollment']
            )
            : collect();
        $this->sectionAccessStates = app(CoursePresentationService::class)
            ->sectionLockStatus(
                $sections,
                $completedSectionIds,
                $learnerStateUser ? (int) $learnerStateUser->id : null
            )
            ->keyBy('section_id');

        // Check if user is enrolled in this course
        $enrollment = $this->resolvedEnrollment;
        if ($user) {
            $this->projectFeedbackLevel = (string) (
                $this->resolvedEntitlement['project_feedback_level']
                ?? 'pass_only'
            );
            if ($enrollment) {
                $plans = app(CourseAccessPlanService::class);
                $terms = $plans->termsForEnrollment($enrollment);
                if ($terms) {
                    $this->projectFeedbackContract = $plans->publicPayloadFromTerms($terms);
                    $variableCostAllowed = (bool) (
                        $this->resolvedEntitlement['chat_available'] ?? false
                    ) || $this->projectFeedbackLevel !== 'pass_only';
                    $this->projectReplyRiskAllowed = (bool) (
                        $this->projectFeedbackContract['project_thread_reply_enabled'] ?? false
                    ) && $variableCostAllowed;
                    $this->projectFeedbackLevel = $variableCostAllowed
                        ? (string) ($this->projectFeedbackContract['project_feedback_level'] ?? 'pass_only')
                        : 'pass_only';
                }
            }
            if ($this->dashboardPreviewUser) {
                $this->projectFeedbackLevel = (string) (
                    $this->projectFeedbackContract['project_feedback_level'] ?? 'pass_only'
                );
                $this->projectReplyRiskAllowed = (bool) (
                    $this->projectFeedbackContract['project_thread_reply_enabled'] ?? false
                );
            }
        }

        $attachments = app(CourseAttachmentService::class);
        $hasCourseAccess = $user
            ? (bool) ($this->resolvedEntitlement['has_learning_access'] ?? false)
            : false;

        $promptFrequency = (string) ($this->attachment_prompt_frequency
            ?: config('course_attachments.prompt.default_frequency', 'once_per_course'));
        if (!array_key_exists(
            $promptFrequency,
            (array) config('course_attachments.prompt.frequencies', [])
        )) {
            $promptFrequency = 'once_per_course';
        }
        $activePdfs = $this->relationLoaded('activePdfs') ? $this->activePdfs : collect();
        $hasDownloadableAttachments = $activePdfs->isNotEmpty();
        $baseData['attachments_count'] = $activePdfs->count();
        if ($hasCourseAccess && $user && $hasDownloadableAttachments) {
            $baseData['attachments'] = $activePdfs
                ->map(fn ($pdf) => $attachments->pdfPayload(
                    $user,
                    $this->resource,
                    $pdf
                ))
                ->values();
        }
        $baseData['attachment_prompt'] = [
            'enabled' => $hasCourseAccess
                && $hasDownloadableAttachments
                && (bool) $this->attachment_prompt_enabled,
            'at_seconds' => max(0, (int) (
                $this->attachment_prompt_at_seconds
                ?? config('course_attachments.prompt.at_seconds', 20)
            )),
            'title' => trim((string) $this->attachment_prompt_title)
                ?: (string) config('course_attachments.prompt.title'),
            'body' => trim((string) $this->attachment_prompt_body)
                ?: (string) config('course_attachments.prompt.body'),
            'button_text' => trim((string) $this->attachment_prompt_button_text)
                ?: (string) config('course_attachments.prompt.button_text'),
            'frequency' => $promptFrequency,
        ];

        // Add enrollment information
        $baseData['enrollment'] = $enrollment ? [
            'id' => $enrollment->id,
            'enrolled_at' => $enrollment->enrolled_at,
            'expires_at' => $enrollment->expires_at,
            'is_active' => $enrollment->isActive(),
            'access_granted_at' => $enrollment->access_granted_at
        ] : null;

        // One logical rating survives soft deletion so restoring it cannot
        // create a duplicate. Its version also protects edits from two devices.
        $userRating = null;
        $ratingEligibility = ['can_rate' => false, 'reason' => 'course_access_required'];
        if ($user) {
            $userRating = CourseRating::withTrashed()->where('user_id', $user->id)
                ->where('course_id', $this->id)
                ->first();
            $ratingEligibility = app(CourseRatingEligibilityService::class)
                ->for($user, $this->resource, $hasCourseAccess, $userRating !== null);
        }

        $baseData['user_rating'] = $userRating && !$userRating->trashed() ? [
            'id' => $userRating->id,
            'rating' => (int)$userRating->rating,
            'comment' => $userRating->comment,
            'version' => (int) $userRating->version,
            'created_at' => $userRating->created_at,
            'updated_at' => $userRating->updated_at,
        ] : null;
        $baseData['rating_eligibility'] = [
            'can_rate' => (bool) $ratingEligibility['can_rate'],
            'reason' => (string) $ratingEligibility['reason'],
            'version' => (int) ($userRating?->version ?? 0),
        ];
        $planAttachmentMax = max(0, (int) ($this->projectFeedbackContract['chat_attachment_max_files'] ?? 0));
        $baseData['chat_attachments_enabled'] = $hasCourseAccess
            && (bool) ($this->projectFeedbackContract['chat_attachments_enabled'] ?? false)
            && $planAttachmentMax > 0;
        $baseData['chat_attachment_max_files'] = $baseData['chat_attachments_enabled']
            ? min(5, $planAttachmentMax)
            : 0;

        // Override modules with full content and lock status for sections
        $baseData['modules'] = $this->whenLoaded('modules', function() {
            $orderedModules = $this->modules->sortBy([
                ['order', 'asc'],
                ['id', 'asc'],
            ])->values();

            return $orderedModules->map(function($module) {
                $moduleSections = $module->sections
                    ? $module->sections->sortBy('order')->values()
                    : collect();
                $firstSection = $moduleSections->first();
                $firstMediaUnavailable = $firstSection
                    && $firstSection->getSectionType() === 'lesson'
                    && !($firstSection->sectionable?->hasReadyMediaState() ?? false);
                $moduleIsLocked = !$firstSection || $firstMediaUnavailable || (
                    $this->isSectionLockedFromState($firstSection)
                    && !(
                        $firstSection->getSectionType() === 'lesson'
                        && (bool) ($firstSection->sectionable?->is_opened ?? false)
                        && $firstSection->sectionable->hasReadyMediaState()
                    )
                );

                $moduleData = [
                    'id' => $module->id,
                    'title' => $module->title,
                    'order' => $module->order,
                    'is_locked' => $moduleIsLocked,
                    'sections' => $moduleSections->map(
                        fn ($section) => $this->learnerSectionPayload($section)
                    ),
                ];

                return $moduleData;
            });
        });

        return $baseData;
    }

    /** Build the entitled section contract used by the canonical module graph. */
    private function learnerSectionPayload($section): array
    {
        $isLocked = $this->isSectionLockedFromState($section);
        $isPreview = $section->getSectionType() === 'lesson'
            && (bool) ($section->sectionable?->is_opened ?? false)
            && $section->sectionable->hasReadyMediaState();
        $mediaUnavailable = $section->getSectionType() === 'lesson'
            && !($section->sectionable?->hasReadyMediaState() ?? false);
        $isLocked = $isLocked || $mediaUnavailable;
        $data = [
            'id' => $section->id,
            'content_id' => $section->sectionable_id,
            'title' => $section->title,
            'type' => $section->getSectionType(),
            'order' => $section->order,
            'module_id' => $section->module_id,
            'is_preview' => $isPreview,
            'is_locked' => $isLocked,
            'is_completed' => (bool) (
                $this->sectionAccessStates->get((int) $section->id)['is_completed'] ?? false
            ),
            'lock_reason' => $mediaUnavailable
                ? 'media_not_ready'
                : ($this->sectionAccessStates->get((int) $section->id)['lock_reason'] ?? null),
        ];

        // Never serialize playable URLs, project requirements or
        // external links for a gated step. Explicit previews remain playable.
        if (!$isLocked || $isPreview) {
            $data['content'] = $this->getFullSectionContent($section);
        }

        return $data;
    }

    protected function isSectionLockedFromState($section): bool
    {
        $sectionId = (int) $section->id;
        if (array_key_exists($sectionId, $this->sectionLockCache)) {
            return $this->sectionLockCache[$sectionId];
        }

        return $this->sectionLockCache[$sectionId] = (bool) (
            $this->sectionAccessStates->get($sectionId)['is_locked'] ?? true
        );
    }

    /**
     * Get full section content including sensitive data
     *
     * @param \App\Models\CourseSection $section
     * @return array
     */
    protected function getFullSectionContent($section)
    {
        $sectionId = (int) $section->id;
        if (array_key_exists($sectionId, $this->fullSectionContentCache)) {
            return $this->fullSectionContentCache[$sectionId];
        }

        if (!$section->sectionable) {
            return null;
        }

        $content = [
            'id' => $section->sectionable->id,
            'title' => $section->sectionable->title ?? $section->sectionable->name_ar ?? null,
            'description' => $section->sectionable->description ?? $section->sectionable->description_ar ?? null,
        ];

        // Add type-specific full data (including sensitive data)
        switch ($section->getSectionType()) {
            case 'lesson':
                $bunnyService = $this->bunnyService ??= app(BunnyService::class);
                $isPreview = (bool) $section->sectionable->is_opened
                    && $section->sectionable->hasReadyMediaState();
                // Paid media is issued only by the per-user playback manifest
                // endpoint. Keeping it out of the broad course payload limits
                // link reuse and makes session telemetry authoritative.
                $videoData = $isPreview
                    ? $bunnyService->getVideoDataForLesson($section->sectionable)
                    : [
                        'video_source_type' => 'bunny',
                        'video_link' => null,
                        'bunny_video_url' => null,
                        'bunny_video_expires_at' => null,
                    ];
                $content['video_source_type'] = $videoData['video_source_type'];
                $content['video_link'] = $videoData['video_link'];
                $content['bunny_video_url'] = $videoData['bunny_video_url'];
                $content['bunny_video_expires_at'] = $videoData['bunny_video_expires_at'];
                $content['is_opened'] = $isPreview;
                $durationSeconds = $this->lessonDurationSeconds($section->sectionable);
                $content['duration_minutes'] = $durationSeconds > 0
                    ? (int) ceil($durationSeconds / 60)
                    : max(0, (int) ($section->sectionable->duration_minutes ?? 0));
                $content['duration_seconds'] = $durationSeconds ?: null;
                $content['thumbnail_url'] = $section->sectionable->thumbnail_path
                    ? $bunnyService->generateBunnySignedUrl($section->sectionable->thumbnail_path)
                    : null;
                break;

                case 'project':
                    $projectSubmissionMimeTypes = app(
                        \App\Services\ProjectSubmissionOrchestrator::class
                    )->allowedMimeTypes($section->sectionable);
                    $content['requirements_text'] = $section->sectionable->requirements_text ?? null;
                    $content['is_graduation_project'] = $section->sectionable->is_graduation_project ?? false;
                    $content['submission_text_enabled'] = (bool) $section->sectionable->submission_text_enabled;
                    $content['submission_files_enabled'] = $projectSubmissionMimeTypes !== [];
                    $content['submission_max_files'] = max(1, min(5, (int) (
                        $section->sectionable->submission_max_files ?: 3
                    )));
                    $content['submission_allowed_mime_types'] = $projectSubmissionMimeTypes;
                    $submission = $this->projectSubmissions->get((int) $section->sectionable->id);
                    $submissionPayload = $submission
                        ? app(ProjectSubmissionPresenter::class)->present($submission, false)
                        : null;
                    $content['latest_submission'] = $submissionPayload;
                    $content['project_feedback'] = [
                        'level' => $this->projectFeedbackLevel,
                        'output_enabled' => (bool) (
                            $this->projectFeedbackContract['project_output_enabled'] ?? false
                        ),
                        'report_enabled' => (bool) (
                            $this->projectFeedbackContract['project_report_enabled'] ?? false
                        ) && $this->projectFeedbackLevel !== 'pass_only',
                        'reply_enabled' => (bool) (
                            $this->projectFeedbackContract['project_thread_reply_enabled'] ?? false
                        ) && $this->projectReplyRiskAllowed,
                        'message_limit' => max(0, (int) (
                            $this->projectFeedbackContract['project_message_limit'] ?? 0
                        )),
                        'token_budget' => max(0, (int) (
                            $this->projectFeedbackContract['project_token_budget'] ?? 0
                        )),
                    ];
               break;

        }

        return $this->fullSectionContentCache[$sectionId] = $content;
    }

}
