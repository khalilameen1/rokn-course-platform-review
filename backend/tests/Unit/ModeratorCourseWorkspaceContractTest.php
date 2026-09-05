<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ModeratorCourseWorkspaceContractTest extends TestCase
{
    public function test_course_workflow_uses_one_shared_context_header(): void
    {
        $header = $this->source('courses/partials/workspace-header.blade.php');

        foreach ([
            'admin.courses.show',
            'admin.courses.student-preview',
        ] as $route) {
            self::assertStringContainsString($route, $header);
        }

        self::assertStringNotContainsString('admin.courses.sections.index', $header);

        self::assertStringContainsString('workspace-header', $this->source('courses/show.blade.php'));
        self::assertFileDoesNotExist(dirname(__DIR__, 2).'/resources/views/admin/courses/edit.blade.php');
        self::assertStringContainsString(
            'course-attachments-panel',
            $this->source('courses/show.blade.php')
        );
    }

    public function test_course_authoring_returns_to_the_same_workspace(): void
    {
        $editor = $this->source('courses/show.blade.php');
        $moduleController = file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/Admin/CourseModuleController.php');
        $sectionController = file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/Admin/CourseSectionController.php');

        self::assertStringContainsString("@include('admin.courses.partials.show.inline-authoring')", $editor);
        self::assertIsString($moduleController);
        self::assertIsString($sectionController);
        self::assertStringContainsString('return $this->authoringRedirect($course);', $moduleController);
        self::assertStringContainsString('return $this->authoringRedirect($course);', $sectionController);
        self::assertStringNotContainsString("view('admin.course-modules.", $moduleController);
        self::assertStringNotContainsString("view('admin.course-sections.", $sectionController);
    }

    public function test_new_course_form_creates_only_the_studio_shell(): void
    {
        $create = $this->source('courses/create.blade.php');

        self::assertStringContainsString("Form::text('name_ar'", $create);
        self::assertStringContainsString('name="authoring_request_id"', $create);
        self::assertStringContainsString('name="certificate_text_template_key"', $create);
        self::assertStringContainsString('إنشاء المسودة والمتابعة', $create);
        self::assertStringNotContainsString('editor.basic-information', $create);
        self::assertStringNotContainsString('edit.access-plans', $create);
    }

    public function test_new_attachment_free_course_does_not_start_with_a_blocking_prompt(): void
    {
        $authoring = file_get_contents(dirname(__DIR__, 2).'/app/Services/AdminCourseAuthoringService.php');
        self::assertIsString($authoring);

        self::assertStringContainsString("'attachment_prompt_enabled' => false", $authoring);
    }

    public function test_course_list_has_one_authoring_entry_point_per_live_course(): void
    {
        $grid = $this->source('courses/partials/index/course-grid.blade.php');

        // The same studio destination is used by the card and its single visible action.
        // A published course may point both at its live identity and at one
        // isolated working draft; the list must resume that draft.
        self::assertSame(2, substr_count($grid, "route('admin.courses.show', \$courseWorkspaceId)"));
        self::assertStringContainsString('$courseHasActiveDraft', $grid);
        self::assertStringNotContainsString("route('admin.courses.edit', \$course->id)", $grid);
        self::assertStringNotContainsString("route('admin.courses.sections.index', \$course->id)", $grid);
    }

    public function test_legacy_parallel_section_index_is_not_exposed(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2).'/routes/web.php');
        $permissions = file_get_contents(dirname(__DIR__, 2).'/app/Auth/AdminPermissionMatrix.php');

        self::assertIsString($routes);
        self::assertIsString($permissions);
        self::assertStringContainsString("Route::resource('courses.sections', 'CourseSectionController')->except(['index', 'show'])", $routes);
        self::assertStringNotContainsString('admin.courses.sections.show', $permissions);
        self::assertStringNotContainsString('admin.courses.sections.index', $permissions);
        self::assertFileDoesNotExist(dirname(__DIR__, 2).'/resources/views/admin/course-sections/index.blade.php');
        self::assertFileDoesNotExist(dirname(__DIR__, 2).'/resources/views/admin/course-sections/show.blade.php');
        self::assertFileDoesNotExist(dirname(__DIR__, 2).'/resources/views/admin/course-sections/create.blade.php');
        self::assertFileDoesNotExist(dirname(__DIR__, 2).'/resources/views/admin/course-sections/edit.blade.php');
    }

    public function test_moderator_home_prioritizes_incomplete_work_without_a_second_editor_entry(): void
    {
        $home = $this->source('home/moderator.blade.php');
        $css = file_get_contents(dirname(__DIR__, 2).'/public/admin/assets/css/moderator-workspace.css');

        self::assertStringContainsString('$coursesNeedingAttention', $home);
        self::assertStringContainsString('$orderedCourses', $home);
        self::assertStringNotContainsString("route('admin.courses.edit', \$course)", $home);
        self::assertIsString($css);
        self::assertStringNotContainsString('linear-gradient', $css);
    }

    public function test_shared_layout_does_not_render_an_empty_breadcrumb_row(): void
    {
        self::assertStringContainsString(
            "@hasSection('breadcrumbs')",
            $this->source('layouts/app.blade.php')
        );
    }

    public function test_administrator_home_leads_with_operational_follow_up(): void
    {
        $dashboard = $this->source('home/index.blade.php');

        self::assertStringContainsString('dashboard-priority-nav', $dashboard);
        foreach ([
            'admin.product-operations.index',
            'admin.playback-operations.index',
            'admin.project-submissions.index',
            'admin.orders.index',
        ] as $route) {
            self::assertStringContainsString($route, $dashboard);
        }
    }

    public function test_administrator_student_and_course_views_lead_with_operational_answers(): void
    {
        $student = $this->source('users/show.blade.php')
            .$this->source('users/partials/show/operations.blade.php')
            .$this->source('users/partials/show/purchases.blade.php');
        $course = $this->source('courses/partials/show/commercial-report.blade.php');

        self::assertStringContainsString('student-operations', $student);
        self::assertStringContainsString('ما دفعه الطالب', $student);
        self::assertStringContainsString('$order->ledger_paid_coins', $student);
        self::assertStringContainsString('$order->ledger_reward_coins', $student);
        self::assertStringContainsString('صافي/طالب', $course);
        self::assertStringContainsString('تكلفة/طالب', $course);
        self::assertStringNotContainsString('<th>OpenRouter</th>', $course);
    }

    public function test_course_commercial_rows_are_paginated_without_truncating_summary_or_export(): void
    {
        $pageService = file_get_contents(dirname(__DIR__, 2).'/app/Services/AdminCoursePageService.php');
        $report = $this->source('courses/partials/show/commercial-report.blade.php');
        $controller = file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/Admin/CourseController.php');
        self::assertIsString($pageService);
        self::assertIsString($controller);

        self::assertStringContainsString("'pageName' => 'commercial_page'", $pageService);
        self::assertStringContainsString('$administrator && $loadCommercialReport', $pageService);
        self::assertStringContainsString("unset(\$report['rows'])", $pageService);
        self::assertStringContainsString("\$commercialReport['student_rows']", $report);
        self::assertStringNotContainsString("\$commercialReport['rows']", $report);
        self::assertStringContainsString("integer('commercial_page', 1)", $controller);
        self::assertStringContainsString("query('tab') === 'commercial-report'", $controller);
    }

    public function test_publish_readiness_does_not_block_moderators_on_hidden_ai_controls(): void
    {
        $service = file_get_contents(dirname(__DIR__, 2).'/app/Services/CoursePublishingService.php');
        $editor = $this->source('courses/partials/show/course-readiness.blade.php');
        self::assertIsString($service);

        foreach (['ميزانية المحادثة', 'ميزانية تقييم المشاريع', 'ميزانية متابعة تقرير المشروع'] as $technicalIssue) {
            self::assertStringNotContainsString('$issues[] = "'.$technicalIssue, $service);
        }
        self::assertSame(1, substr_count($editor, "publishingAudit['issues']"));
    }

    public function test_course_publish_is_one_explicit_save_or_publish_operation(): void
    {
        $editor = $this->source('courses/partials/show/course-settings-panel.blade.php');
        $settings = $this->source('courses/partials/edit/course-settings.blade.php');
        $authoring = file_get_contents(dirname(__DIR__, 2).'/app/Services/AdminCourseAuthoringService.php');
        $request = file_get_contents(dirname(__DIR__, 2).'/app/Http/Requests/Admin/CourseRequest.php');

        self::assertIsString($authoring);
        self::assertIsString($request);
        self::assertStringContainsString('name="publishing_intent" value="save"', $editor);
        self::assertStringContainsString('name="publishing_intent" value="publish"', $editor);
        self::assertStringNotContainsString("Form::checkbox('is_coming_soon'", $settings);
        self::assertStringContainsString("input('publishing_intent') === 'publish'", $authoring);
        self::assertStringContainsString("Rule::in(['save', 'publish'])", $request);
    }

    public function test_next_revision_editor_preserves_live_catalogue_and_hero_choices(): void
    {
        $pages = file_get_contents(dirname(__DIR__, 2).'/app/Services/AdminCoursePageService.php');
        $settings = $this->source('courses/partials/edit/course-settings.blade.php');

        self::assertIsString($pages);
        self::assertStringContainsString("'catalogVisibilityDefault' => \$managedDraft", $pages);
        self::assertStringContainsString("'mainCourseDefault' => \$managedDraft", $pages);
        self::assertStringContainsString('$catalogVisibilityDefault', $settings);
        self::assertStringContainsString('$mainCourseDefault', $settings);
        self::assertStringContainsString('activeDraftFor($course) ?: $course', $pages);
    }

    public function test_draft_preview_keeps_device_link_on_the_published_course(): void
    {
        $service = file_get_contents(dirname(__DIR__, 2).'/app/Services/AdminCoursePreviewService.php');
        $preview = $this->source('courses/student-preview.blade.php');
        $routes = file_get_contents(dirname(__DIR__, 2).'/routes/web.php');

        self::assertIsString($service);
        self::assertIsString($routes);
        self::assertStringContainsString('canonicalFor($previewCourse)', $service);
        self::assertStringContainsString("'publishedDeviceCourseId'", $service);
        self::assertStringContainsString("'rokn://course/'.\$publishedDeviceCourseId", $preview);
        self::assertStringNotContainsString("'rokn://course/'.\$previewCourse->id", $preview);
        self::assertStringContainsString(
            "Route::get('courses/{course}/student-preview', 'CourseController@studentPreview')",
            $routes
        );
        self::assertStringNotContainsString(
            "courses/{course}/student-preview', 'CourseController@studentPreview')\n        ->middleware('course.draft')",
            str_replace("\r\n", "\n", $routes)
        );
    }

    public function test_publish_readiness_points_to_the_owning_editor_area(): void
    {
        $publishing = file_get_contents(dirname(__DIR__, 2).'/app/Services/CoursePublishingService.php');
        $editor = $this->source('courses/partials/show/course-readiness.blade.php')
            .$this->source('courses/partials/publishing-area-issues.blade.php');

        self::assertIsString($publishing);
        self::assertStringContainsString("'issue_details' => \$this->describeIssues(\$issues)", $publishing);
        self::assertStringContainsString("publishingAudit['issues']", $editor);
        self::assertStringContainsString("publishingAudit['issue_details']", $editor);
    }

    public function test_course_studio_does_not_load_or_render_student_operations_for_moderators(): void
    {
        $pageService = file_get_contents(dirname(__DIR__, 2).'/app/Services/AdminCoursePageService.php');
        $studio = $this->source('courses/partials/show/course-overview.blade.php');
        self::assertIsString($pageService);

        self::assertStringContainsString('if ($administrator) {', $pageService);
        self::assertStringContainsString('$reportCourse->loadCount(\'activeEnrollments\')', $pageService);
        self::assertStringContainsString("'learningHealthSummary' => \$administrator", $pageService);
        self::assertStringContainsString('@if($activeStudentsCount !== null)', $studio);
    }

    public function test_course_studio_panels_have_one_owner_for_each_dom_id(): void
    {
        $studio = $this->source('courses/show.blade.php');
        $statistics = $this->source('courses/partials/show/statistics.blade.php');
        $commercial = $this->source('courses/partials/show/commercial-report.blade.php');

        self::assertSame(1, substr_count($studio, 'id="statistics"'));
        self::assertSame(1, substr_count($studio, 'id="commercial-report"'));
        self::assertStringNotContainsString('id="statistics"', $statistics);
        self::assertStringNotContainsString('id="commercial-report"', $commercial);
        self::assertStringNotContainsString('courses-show.css', $studio);
    }

    private function source(string $view): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/'.$view);
        self::assertNotFalse($source, $view);

        return $source;
    }
}
