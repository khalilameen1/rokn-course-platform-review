<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseCode;

final readonly class AdminCoursePreviewService
{
    public function __construct(
        private CourseReadService $courseReads,
        private CourseAccessPlanService $plans,
        private CertificateTextTemplateService $certificateTemplates,
        private CourseRevisionResolver $revisionResolver
    ) {
    }

    /** @return array<string, mixed> */
    public function prepare(Course $course, ?string $requestedPlan): array
    {
        $course = $this->revisionResolver->activeDraftFor($course) ?: $course;
        $previewCourse = $this->courseReads->detailedCourseForAdminPreview((int) $course->id);
        $planOptions = $this->plans->publicPlans($previewCourse)
            ->map(fn ($plan): array => $this->plans->publicPayload($plan))
            ->values();
        $hasGrant = CourseCode::query()
            ->where('course_id', $previewCourse->id)
            ->valid()
            ->get()
            ->contains(fn (CourseCode $code): bool => $code->isInstitutionalGrant());

        if ($hasGrant) {
            $planOptions->push($this->grantPlan());
        }
        if ($planOptions->isEmpty()) {
            return ['error' => 'أنشئ فئة نشطة قبل معاينة تجربة الطالب.'];
        }

        $requestedPlan = strtolower(trim((string) $requestedPlan));
        $selectedPlan = $requestedPlan !== ''
            ? $planOptions->firstWhere('code', $requestedPlan)
            : ($planOptions->firstWhere('code', CourseAccessPlan::BASIC) ?? $planOptions->first());
        if (!$selectedPlan) {
            return ['error' => 'هذه الفئة لم تعد متاحة للمعاينة.'];
        }

        $certificateTextTemplate = $this->certificateTemplates->forIssuance($previewCourse);
        if ($certificateTextTemplate === null) {
            return ['error' => 'اختر صياغة شهادة صالحة قبل معاينة تجربة الطالب.'];
        }

        $publishedCourse = $this->revisionResolver->canonicalFor($previewCourse);
        $publishedDeviceCourseId = $publishedCourse->isPublishedForLearning()
            ? (int) $publishedCourse->id
            : null;

        return [
            'error' => null,
            'previewCourse' => $previewCourse,
            'planOptions' => $planOptions,
            'selectedPlan' => $selectedPlan,
            'certificateTextTemplate' => $certificateTextTemplate,
            // The selected course deliberately represents the working draft. The
            // device deep link must keep pointing at the immutable learner
            // revision, not at the hidden draft row produced by middleware.
            'publishedDeviceCourseId' => $publishedDeviceCourseId,
            'isWorkingDraftPreview' => (bool) $previewCourse->is_coming_soon,
        ];
    }

    /** @return array<string, mixed> */
    private function grantPlan(): array
    {
        return [
            'code' => 'grant',
            'name' => 'منحة مشاهدة الكورس',
            'price_coins' => 0,
            'minimum_paid_coins' => 0,
            'chat_enabled' => false,
            'chat_message_limit' => 0,
            'chat_attachments_enabled' => false,
            'chat_attachment_max_files' => 0,
            'project_feedback_level' => 'pass_only',
            'project_report_enabled' => false,
            'project_thread_reply_enabled' => false,
            'project_message_limit' => 0,
            'project_token_budget' => 0,
            'project_attachments_enabled' => false,
            'project_attachment_max_files' => 0,
            'project_output_enabled' => false,
            'certificate_enabled' => false,
            'projects_enabled' => false,
        ];
    }
}
