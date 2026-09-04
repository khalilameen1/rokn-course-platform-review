<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CourseAuthoringPolicyTest extends TestCase
{
    public function test_publishing_allows_theory_modules_without_crossing_projects(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/app/Services/CoursePublishingService.php');

        self::assertNotFalse($source);
        self::assertStringNotContainsString('أضف مشروع العبور بعد آخر خطوة', $source);
        self::assertStringContainsString('$projects->count() > 1', $source);
        self::assertStringContainsString('$projects->isNotEmpty()', $source);
        self::assertStringContainsString('$graduationProjectsCount === 1', $source);
    }

    public function test_publish_readiness_matches_the_player_media_generation_contract(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/app/Services/CoursePublishingService.php');

        self::assertNotFalse($source);
        self::assertStringContainsString("\$mediaState->status !== 'ready'", $source);
        self::assertStringContainsString("\$mediaState->integrity_status === 'quarantined'", $source);
        self::assertStringContainsString('$mediaState->quarantined_at !== null', $source);
        self::assertStringContainsString('(int) $mediaState->duration_seconds < 1', $source);
        self::assertStringContainsString('strtolower(trim((string) $lesson->bunny_video_id))', $source);
    }

    public function test_authoring_ui_exposes_one_visible_reel_title_and_a_separate_caption(): void
    {
        $studio = file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/courses/partials/show/inline-authoring.blade.php');
        $outline = file_get_contents(dirname(__DIR__, 2).'/public/admin/assets/js/course-studio-outline.js');
        $sectionEditor = file_get_contents(dirname(__DIR__, 2).'/public/admin/assets/js/course-studio-section-editor.js');
        $scripts = (string) $outline.(string) $sectionEditor;

        self::assertIsString($studio);
        self::assertIsString($scripts);
        self::assertStringContainsString('<label for="title_ar">العنوان</label>', $studio);
        self::assertStringContainsString('<label for="lesson_description_ar">الكابشن</label>', $studio);
        self::assertStringContainsString('name="section_type"', $studio);
        self::assertStringContainsString("button.dataset.inlineEditorOpen = 'lesson'", $scripts);
        self::assertStringContainsString('data-inline-editor-open="project"', $scripts);
        self::assertStringNotContainsString('lesson-title-sync-fields', $studio.$scripts);
        self::assertStringNotContainsString('quiz', $studio.$scripts);
        self::assertStringNotContainsString('exam', $studio.$scripts);
        self::assertStringNotContainsString('اختبار الوحدة', $studio.$scripts);
    }

    public function test_modules_only_ask_moderators_for_a_title(): void
    {
        $studio = file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/courses/partials/show/inline-authoring.blade.php');
        self::assertIsString($studio);
        self::assertStringContainsString('id="studioInlineModuleName" name="title_ar"', $studio);
        self::assertStringNotContainsString('attachment_platform', $studio);
        self::assertStringNotContainsString('name="description_ar"', $studio);
        self::assertStringNotContainsString('name="description_en"', $studio);

        foreach (['CourseResource.php', 'BaseCourseResource.php'] as $resource) {
            $source = file_get_contents(dirname(__DIR__, 2)."/app/Http/Resources/{$resource}");

            self::assertNotFalse($source);
            self::assertStringNotContainsString("'description' => \$module->description", $source);
        }

        $actions = file_get_contents(dirname(__DIR__, 2).'/app/Services/AdminCourseModuleApplicationService.php');
        self::assertNotFalse($actions);
        self::assertStringContainsString("'module' => \$this->outline->module", $actions);
        self::assertStringContainsString("'authoring_version' => \$version", $actions);
        self::assertStringNotContainsString('attachment_platform', $actions);
    }

    public function test_project_authoring_cannot_submit_ai_runtime_policy(): void
    {
        $controller = file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/Admin/CourseSectionController.php'
        );
        self::assertNotFalse($controller);
        foreach (['$request->ai_prompt', '$request->ai_model_type', '$request->temperature', '$request->tokens_number', '$request->passing_score'] as $input) {
            self::assertStringNotContainsString($input, $controller);
        }

        $project = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/admin/courses/partials/show/inline-authoring.blade.php'
        );
        self::assertNotFalse($project);
        self::assertStringContainsString('project_requirements_ar', $project);
        self::assertStringNotContainsString('ai_prompt', $project);
        self::assertStringNotContainsString('tokens_number', $project);
    }

    public function test_project_delivery_types_flow_from_dashboard_to_the_learner_contract(): void
    {
        $form = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/admin/courses/partials/show/inline-authoring.blade.php'
        );
        $input = file_get_contents(
            dirname(__DIR__, 2).'/app/Services/CourseSectionInput.php'
        );
        $content = file_get_contents(
            dirname(__DIR__, 2).'/app/Services/CourseSectionContentService.php'
        );
        $resource = file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Resources/CourseResource.php'
        );
        $projectTypes = file_get_contents(dirname(__DIR__, 2).'/config/projects.php');

        foreach ([$form, $input, $content, $resource, $projectTypes] as $source) {
            self::assertNotFalse($source);
        }

        self::assertStringContainsString('name="project_submission_types[]"', $form);
        self::assertStringContainsString("config('projects.submission_types'", $form);
        self::assertStringContainsString("'project_submission_types.*'", $input);
        self::assertStringContainsString("'submission_text_enabled' => \$selectedTypes->contains('text')", $content);
        self::assertStringContainsString("'submission_allowed_mime_types' => \$allowedMimeTypes", $content);
        self::assertStringNotContainsString("'priority' => \$order", $content);
        self::assertStringContainsString("\$content['submission_text_enabled']", $resource);
        self::assertStringContainsString("\$content['submission_files_enabled']", $resource);
        self::assertStringContainsString("\$content['submission_allowed_mime_types']", $resource);
        self::assertStringNotContainsString("'video/mp4'", $projectTypes);
    }

    public function test_ai_runtime_uses_only_the_immutable_submission_contract(): void
    {
        $sources = [
            file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/API/CourseChatController.php'),
            file_get_contents(dirname(__DIR__, 2).'/app/Jobs/GenerateProjectFeedback.php'),
            file_get_contents(dirname(__DIR__, 2).'/app/Jobs/GenerateProjectFeedbackReply.php'),
        ];

        foreach ($sources as $source) {
            self::assertNotFalse($source);
            self::assertStringNotContainsString("['model_override']", $source);
        }

        self::assertStringNotContainsString(
            'currentLearnerEntityMap(',
            (string) $sources[1]
        );
        self::assertStringNotContainsString(
            'currentLearnerEntityMap(',
            (string) $sources[2]
        );
        self::assertStringContainsString(
            'ProjectSubmissionEvaluationSnapshot::fromSubmission',
            (string) $sources[1]
        );
        self::assertStringContainsString(
            'ProjectSubmissionEvaluationSnapshot::fromSubmission',
            (string) $sources[2]
        );
    }

    public function test_unknown_provider_outcome_is_not_exposed_as_a_retryable_generic_failure(): void
    {
        $controller = file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/API/CourseChatController.php'
        );
        $failurePolicy = file_get_contents(
            dirname(__DIR__, 2).'/app/Services/AiFailurePolicy.php'
        );

        self::assertNotFalse($controller);
        self::assertNotFalse($failurePolicy);
        self::assertStringContainsString('$this->failurePolicy->describe($code)', $controller);
        self::assertStringContainsString("'can_retry' => \$failure['can_retry']", $controller);
        self::assertStringContainsString("'chat_provider_outcome_unknown'", $failurePolicy);
        self::assertStringContainsString("'unknown_outcome', false, 0", $failurePolicy);
        self::assertStringNotContainsString('terminalFailureCanRetry', $controller);
    }

    public function test_idempotent_chat_admission_reconciles_the_usage_ledger_before_retry_policy(): void
    {
        $admission = file_get_contents(
            dirname(__DIR__, 2).'/app/Services/CourseChatAdmissionService.php'
        );
        $controller = file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/API/CourseChatController.php'
        );

        self::assertNotFalse($admission);
        self::assertNotFalse($controller);
        self::assertStringContainsString(
            '$this->turns->reconcileTerminalUsage($turn)',
            $admission
        );
        self::assertStringContainsString(
            "new CourseChatAdmissionResult('terminal', \$terminal, \$claimed)",
            $admission
        );
        self::assertStringNotContainsString(
            "new CourseChatAdmissionResult('failed'",
            $admission
        );
        self::assertStringNotContainsString("\$admission->state === 'failed'", $controller);
    }
}
