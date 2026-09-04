<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminCourseSectionStateContractTest extends TestCase
{
    public function test_bunny_resume_state_is_owned_expiring_and_terminal_errors_are_machine_readable(): void
    {
        $view = $this->source('resources/views/admin/course-sections/partials/bunny-direct-upload.blade.php');
        $service = $this->source('app/Services/BunnyDirectUploadService.php');
        $controller = $this->source('app/Http/Controllers/Admin/CourseSectionVideoUploadController.php');

        self::assertStringContainsString('const ownerId = @json((string) auth()->id());', $view);
        self::assertStringContainsString(
            'rokn:bunny-upload:${ownerId}:${courseId}:${sectionId()}:${tabId}:${fingerprintKey(file)}',
            $view
        );
        self::assertStringContainsString('Number(saved.version) === recordVersion', $view);
        self::assertStringContainsString('String(saved.ownerId) === ownerId', $view);
        self::assertStringContainsString('claimExpiresAt <= Date.now()', $view);
        self::assertStringContainsString("'bunny_upload_claim_unavailable'", $view);
        self::assertStringContainsString("'bunny_upload_operation_unavailable'", $view);
        self::assertStringContainsString('serverRejectedClaim', $view);
        self::assertStringContainsString('const upload = async (file, restartCount = 0)', $view);
        self::assertStringContainsString('if (restartCount >= 1)', $view);
        self::assertStringNotContainsString("message || '').includes", $view);

        self::assertStringContainsString("'bunny_video_claim_terminal' => 'claim_unavailable'", $service);
        self::assertStringContainsString("'bunny_upload_operation_terminal' => 'operation_unavailable'", $service);
        self::assertStringContainsString("'claim_expires_at' => gmdate", $service);
        self::assertStringContainsString("'bunny_upload_claim_unavailable'", $controller);
        self::assertStringContainsString("'bunny_upload_operation_unavailable'", $controller);
        self::assertStringContainsString('], 410);', $controller);
    }

    public function test_dashboard_video_batches_and_lost_probe_dispatches_have_a_recovery_path(): void
    {
        $routes = $this->source('routes/web.php');
        $kernel = $this->source('app/Console/Kernel.php');
        $recovery = $this->source('app/Console/Commands/RecoverPendingLessonMedia.php');

        self::assertStringContainsString("'throttle:30,1'", $routes);
        self::assertStringContainsString(
            "media:recover-pending --limit=200 --stale-minutes=2",
            $kernel
        );
        self::assertStringContainsString("whereIn('status', ['unknown', 'processing'])", $recovery);
        self::assertStringContainsString("->where('status', 'ready')", $recovery);
        self::assertStringContainsString("->whereNull('last_reconciled_at')", $recovery);
        self::assertStringNotContainsString("is_coming_soon", $recovery);
        self::assertStringContainsString('new ProbeLessonMedia(', $recovery);
    }

    public function test_course_section_forms_have_one_bunny_only_media_contract(): void
    {
        $form = $this->source('resources/views/admin/courses/partials/show/inline-authoring.blade.php');
        $script = $this->source('public/admin/assets/js/course-studio-section-editor.js');

        self::assertStringContainsString('name="video_source_type" value="bunny"', $form);
        self::assertStringContainsString('data-bunny-upload-init=', $form);
        self::assertStringContainsString('RoknCourseVideoUpload', $script);
        self::assertStringNotContainsString('video_source_youtube', $script);
        self::assertStringNotContainsString('updateVideoSourceUI', $script);
    }

    public function test_completed_bunny_upload_can_continue_into_the_intended_form_submission(): void
    {
        $view = $this->source('resources/views/admin/course-sections/partials/bunny-direct-upload.blade.php');
        $draft = $this->source('resources/views/admin/partials/course-authoring-draft.blade.php');

        self::assertStringContainsString("await upload(currentFile);\n            uploading = false;", $view);
        self::assertStringContainsString('if (!uploading || submittingAfterUpload) return;', $view);
        self::assertStringContainsString('if (!event.defaultPrevented) return;', $view);
        self::assertStringContainsString('submittingAfterUpload = false;', $view);
        self::assertStringContainsString('window.RoknCourseVideoUpload = Object.freeze({', $view);
        self::assertStringContainsString('resetAfterCommit: () => resetRuntime(true)', $view);
        self::assertStringContainsString('window.setTimeout(() => controller.abort(), 20000)', $view);
        self::assertStringContainsString("if (error?.name === 'AbortError') throw new Error('تعذر بدء الرفع بسبب بطء الاتصال')", $view);
        self::assertStringContainsString('setSectionContext: (nextSectionId, videoRequired = true) => {', $view);
        self::assertStringContainsString("form.dataset.sectionId = nextSectionId ? String(nextSectionId) : '';", $view);
        self::assertStringContainsString("fileInput.toggleAttribute('required', required);", $view);
        self::assertStringContainsString('if (event.defaultPrevented) {', $draft);
        self::assertStringContainsString('submitQueued = false;', $draft);
    }

    public function test_inline_authoring_mutations_return_json_instead_of_redirects(): void
    {
        $modules = $this->source('app/Http/Controllers/Admin/CourseModuleController.php');
        $moduleActions = $this->source('app/Services/AdminCourseModuleApplicationService.php');
        $sections = $this->source('app/Http/Controllers/Admin/CourseSectionController.php');
        $intents = $this->source('app/Services/AdminAuthoringCreateIntentService.php');

        self::assertStringContainsString("'module' => \$this->outline->module", $moduleActions);
        self::assertStringContainsString('return response()->json($payload);', $modules);
        self::assertStringContainsString("'section' => \$sectionPayload", $sections);
        self::assertStringContainsString('$this->outline->section($lockedCourse, $section)', $sections);
        self::assertStringContainsString("'section_ids' => \$deletedSectionIds", $moduleActions);
        self::assertStringContainsString("'deleted_module_id' => (int) \$module->id", $moduleActions);
        self::assertStringContainsString("'deleted_section_id' => (int) \$section->id", $sections);
        self::assertStringContainsString("'modules' => \$this->outline->graph(\$lockedCourse->fresh())['modules']", $moduleActions);
        self::assertStringContainsString("'modules' => \$this->outline->graph(\$lockedCourse->fresh())['modules']", $sections);
        self::assertStringContainsString('$this->deletion->deleteSection($lockedSection)', $sections);
        self::assertStringContainsString("'authoring_in_progress',", $intents);
        self::assertStringContainsString('if ($request->expectsJson()) {', $intents);
    }

    public function test_course_creation_only_creates_an_idempotent_shell_before_the_studio(): void
    {
        $create = $this->source('resources/views/admin/courses/create.blade.php');
        $controller = $this->source('app/Http/Controllers/Admin/CourseController.php');
        $authoring = $this->source('app/Services/AdminCourseAuthoringService.php');

        self::assertStringContainsString("Form::text('name_ar'", $create);
        self::assertStringContainsString('name="authoring_request_id"', $create);
        self::assertStringContainsString('name="certificate_text_template_key"', $create);
        self::assertStringContainsString('إنشاء المسودة والمتابعة', $create);
        self::assertStringNotContainsString('access_plans', $create);
        self::assertStringNotContainsString('name="image"', $create);
        self::assertStringNotContainsString('editor-scripts', $create);
        self::assertStringContainsString("'is_coming_soon' => true", $authoring);
        self::assertStringContainsString("'is_catalog_visible' => false", $authoring);
        self::assertStringContainsString("redirect()->route('admin.courses.show', \$course)", $controller);
    }

    public function test_studio_has_one_serialized_aggregate_mutation_owner(): void
    {
        $core = $this->source('public/admin/assets/js/course-studio-core.js');
        $outline = $this->source('public/admin/assets/js/course-studio-outline.js');
        $coordinator = $this->source('public/admin/assets/js/course-studio-editor-coordinator.js');
        $sectionEditor = $this->source('public/admin/assets/js/course-studio-section-editor.js');
        $moduleEditor = $this->source('public/admin/assets/js/course-studio-module-editor.js');
        $details = $this->source('public/admin/assets/js/course-studio-details.js');
        $attachments = $this->source('public/admin/assets/js/course-studio-attachments.js');
        $show = $this->source('resources/views/admin/courses/show.blade.php');
        $header = $this->source('resources/views/admin/courses/partials/workspace-header.blade.php');

        self::assertSame(1, substr_count($core, "serializeMutation('course-studio-authoring'"));
        foreach ([$outline, $sectionEditor, $moduleEditor, $details, $attachments] as $module) {
            self::assertStringNotContainsString('serializeMutation(', $module);
            self::assertStringContainsString('core.mutate(', $module);
            self::assertStringContainsString('core.mutationHeaders(', $module);
            self::assertStringNotContainsString('core.csrf', $module);
        }
        foreach ([$sectionEditor, $moduleEditor, $details, $attachments] as $formMutation) {
            self::assertStringContainsString('core.authoringFormData(', $formMutation);
        }
        self::assertStringContainsString("body.set('_method', method)", $core);
        self::assertStringContainsString("if (feedback) showFeedback(feedback, message)", $core);
        self::assertStringContainsString("else notify(message, true)", $core);
        self::assertStringContainsString("sessionStorage.setItem(reconciliationMessageKey, message)", $core);
        self::assertStringContainsString('timeout: 30000', $details);
        self::assertStringNotContainsString('timeout: 45000', $details);
        self::assertStringNotContainsString('window.location.assign(course.studio_url)', $details);
        self::assertStringContainsString('applySavedCourse(course)', $details);
        self::assertStringContainsString("core.provide('editor-coordinator'", $coordinator);
        self::assertStringContainsString("coordinator.register('section'", $sectionEditor);
        self::assertStringContainsString("coordinator.register('module'", $moduleEditor);
        self::assertStringContainsString('requireMutation = (response, expectedVersion, requireAdvance = true)', $core);
        self::assertStringNotContainsString('<iframe', $show.$header);
        self::assertStringNotContainsString('admin.courses.edit', $show.$header);
        self::assertStringNotContainsString('admin.courses.pdfs.index', $show.$header);
        self::assertStringNotContainsString('$workspaceActive', $header);
        self::assertStringNotContainsString('$workspaceInlineTools', $header);
    }

    public function test_module_and_section_deletion_share_one_cleanup_owner(): void
    {
        $moduleActions = $this->source('app/Services/AdminCourseModuleApplicationService.php');
        $sections = $this->source('app/Http/Controllers/Admin/CourseSectionController.php');
        $deletion = $this->source('app/Services/CourseAuthoringDeletionService.php');
        $projectFiles = $this->source('app/Services/ProjectSubmissionFileRetentionService.php');

        self::assertStringContainsString('$this->deletion->deleteModule($lockedModule)', $moduleActions);
        self::assertStringContainsString("'section_ids' => \$deletedSectionIds", $moduleActions);
        self::assertStringContainsString('$this->deletion->deleteSection($lockedSection)', $sections);
        self::assertStringNotContainsString('attachments()', $deletion);
        self::assertStringNotContainsString('StoredFileDeletionService', $deletion);
        self::assertStringContainsString('$this->projectFiles->purgeForDeletedProject($content)', $deletion);
        self::assertStringContainsString('OWNER_PROJECT_SUBMISSION', $projectFiles);
        self::assertStringContainsString('OWNER_PROJECT_FEEDBACK_MESSAGE', $projectFiles);
    }

    public function test_publishing_a_draft_preserves_the_explicit_catalog_visibility_choice(): void
    {
        $authoring = $this->source('app/Services/AdminCourseAuthoringService.php');

        self::assertStringContainsString('$catalogVisible,', $authoring);
        self::assertStringContainsString("'is_coming_soon' => false", $authoring);
        self::assertStringContainsString(
            "'is_catalog_visible' => \$catalogVisible",
            $authoring
        );
    }

    private function source(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        self::assertNotFalse($source, $path);

        return $source;
    }
}
