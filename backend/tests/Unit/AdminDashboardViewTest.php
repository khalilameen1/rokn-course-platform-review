<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdminDashboardViewTest extends TestCase
{
    #[DataProvider('dashboardViews')]
    public function test_dashboard_views_use_the_shared_stylesheet(string $view): void
    {
        $source = $this->viewSource($view);

        self::assertStringNotContainsString('<style', $source);
        self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $source);
        self::assertStringContainsString('admin-', $source);
    }

    #[DataProvider('mfaViews')]
    public function test_mfa_pages_use_the_admin_auth_layout(string $view): void
    {
        $source = $this->viewSource($view);

        self::assertStringContainsString("@extends('admin.layouts.auth')", $source);
        self::assertStringNotContainsString('<style', $source);
    }

    public function test_course_studio_keeps_its_editor_in_shared_partials(): void
    {
        $editor = $this->viewSource('courses/show.blade.php');
        $settingsPanel = $this->viewSource('courses/partials/show/course-settings-panel.blade.php');
        $behavior = $this->viewSource('courses/partials/editor-scripts.blade.php');
        $partials = [
            'editor.basic-information' => 'courses/partials/editor/basic-information.blade.php',
            'edit.course-settings' => 'courses/partials/edit/course-settings.blade.php',
            'edit.access-plans' => 'courses/partials/edit/access-plans.blade.php',
            'editor.course-image' => 'courses/partials/editor/course-image.blade.php',
        ];

        self::assertStringContainsString('admin/assets/css/course-editor.css', $editor);
        self::assertMatchesRegularExpression(
            '/<section\b[^>]*class="[^"]*\bcourse-editor\b[^"]*"[^>]*id="studioCoursePanel"/',
            $settingsPanel
        );
        self::assertLessThanOrEqual(350, substr_count($editor, "\n") + 1);

        $formSource = $editor.$settingsPanel.$behavior;
        foreach ($partials as $include => $view) {
            $source = $this->viewSource($view);

            self::assertStringContainsString(
                "@include('admin.courses.partials.{$include}'",
                $settingsPanel
            );
            $this->assertNoInlineStyles($source, $view);
            self::assertLessThanOrEqual(250, substr_count($source, "\n") + 1, $view);
            $formSource .= $source;
        }

        $this->assertNoInlineStyles($editor, 'courses/show.blade.php');
        foreach ([
            'classification_ids[]',
            'teacher_ids[]',
            'access_plans[{{ $code }}][price_coins]',
        ] as $fieldName) {
            self::assertStringContainsString($fieldName, $formSource);
        }

        self::assertStringContainsString("@include('admin.courses.partials.editor-scripts'", $editor);
        self::assertStringContainsString("document.addEventListener('DOMContentLoaded'", $behavior);
        self::assertStringContainsString('const showImage = file =>', $behavior);
        self::assertStringContainsString('const refreshState = () =>', $behavior);
        self::assertStringContainsString('<label class="file-upload-area" for="image">', $formSource);
        self::assertStringNotContainsString('onclick="document.getElementById(\'image\').click()"', $formSource);

        $stylesheet = file_get_contents(
            dirname(__DIR__, 2).'/public/admin/assets/css/course-editor.css'
        );
        self::assertNotFalse($stylesheet);
        self::assertStringContainsString('.course-editor', $stylesheet);
    }

    public function test_course_sections_use_the_single_inline_studio_editor(): void
    {
        $editor = $this->viewSource('courses/partials/show/inline-authoring.blade.php');
        $outline = file_get_contents(dirname(__DIR__, 2).'/public/admin/assets/js/course-studio-outline.js');
        $coordinator = file_get_contents(dirname(__DIR__, 2).'/public/admin/assets/js/course-studio-editor-coordinator.js');
        $sectionEditor = file_get_contents(dirname(__DIR__, 2).'/public/admin/assets/js/course-studio-section-editor.js');
        $moduleEditor = file_get_contents(dirname(__DIR__, 2).'/public/admin/assets/js/course-studio-module-editor.js');

        self::assertIsString($outline);
        self::assertIsString($coordinator);
        self::assertIsString($sectionEditor);
        self::assertIsString($moduleEditor);
        $this->assertNoInlineStyles($editor, 'courses/partials/show/inline-authoring.blade.php');
        foreach ([
            'section_type',
            'title_ar',
            'lesson_duration_minutes',
            'video_source_type',
            'bunny_video',
            'project_requirements_ar',
            'project_submission_types',
        ] as $fieldName) {
            self::assertStringContainsString($fieldName, $editor);
        }
        self::assertStringContainsString("RoknCourseStudio.register('outline'", $outline);
        self::assertStringContainsString("RoknCourseStudio.register('editor-coordinator'", $coordinator);
        self::assertStringContainsString("RoknCourseStudio.register('section-editor'", $sectionEditor);
        self::assertStringContainsString("core.provide('section-editor', {openNew})", $sectionEditor);
        self::assertStringContainsString("RoknCourseStudio.register('module-editor'", $moduleEditor);
        self::assertFileDoesNotExist(dirname(__DIR__, 2).'/resources/views/admin/course-sections/create.blade.php');
        self::assertFileDoesNotExist(dirname(__DIR__, 2).'/resources/views/admin/course-sections/edit.blade.php');
    }

    public function test_admin_runtime_assets_are_cache_busted_by_the_deployed_file(): void
    {
        $layout = $this->viewSource('layouts/app.blade.php');

        foreach (['js/app.js', 'admin/assets/js/main.js', 'admin/assets/js/request.js'] as $path) {
            self::assertStringContainsString(
                "versioned_asset('{$path}')",
                $layout,
                $path
            );
        }
    }

    #[DataProvider('orderScreens')]
    public function test_order_screens_keep_their_contracts_in_small_partials(
        string $screen,
        array $partials,
        array $expectedContracts
    ): void {
        $view = "orders/{$screen}.blade.php";
        $screenSource = $this->viewSource($view);

        self::assertLessThanOrEqual(70, substr_count($screenSource, "\n") + 1);
        $this->assertNoInlineStyles($screenSource, $view);

        $source = $screenSource;
        foreach ($partials as $partial) {
            $partialView = "orders/partials/{$screen}/{$partial}.blade.php";
            $partialSource = $this->viewSource($partialView);

            self::assertStringContainsString(
                "@include('admin.orders.partials.{$screen}.{$partial}')",
                $screenSource
            );
            $this->assertNoInlineStyles($partialSource, $partialView);
            self::assertLessThanOrEqual(250, substr_count($partialSource, "\n") + 1, $partialView);
            $source .= $partialSource;
        }

        foreach ($expectedContracts as $contract) {
            self::assertStringContainsString($contract, $source);
        }
    }

    public function test_course_create_is_a_thin_shell_that_enters_the_studio(): void
    {
        $shell = $this->viewSource('courses/create.blade.php');

        self::assertLessThanOrEqual(75, substr_count($shell, "\n") + 1);
        $this->assertNoInlineStyles($shell, 'courses/create.blade.php');
        self::assertStringContainsString("route' => ['admin.courses.store']", $shell);
        self::assertStringContainsString("Form::text('name_ar'", $shell);
        self::assertStringContainsString('name="authoring_request_id"', $shell);
        self::assertStringContainsString('name="certificate_text_template_key"', $shell);
        self::assertStringContainsString('course-authoring-draft', $shell);
        self::assertStringNotContainsString('editor.basic-information', $shell);
        self::assertFileDoesNotExist(dirname(__DIR__, 2).'/public/admin/assets/css/course-create.css');
    }

    public function test_ai_policy_is_owned_by_admin_settings_not_course_authoring(): void
    {
        $settings = $this->viewSource('settings/index.blade.php');
        $create = $this->viewSource('courses/create.blade.php');
        $studio = $this->viewSource('courses/show.blade.php');
        $courseSettings = $this->viewSource('courses/partials/show/course-settings-panel.blade.php');
        $plans = $this->viewSource('courses/partials/edit/access-plans.blade.php');

        self::assertStringContainsString('ai_plan_policy[{{ $code }}][chat_enabled]', $settings);
        self::assertStringContainsString('ai_plan_policy[{{ $code }}][project_feedback_level]', $settings);
        self::assertStringNotContainsString('ai-settings', $create.$studio.$courseSettings);
        self::assertStringNotContainsString(
            'name="access_plans[{{ $code }}][chat_enabled]"',
            $plans
        );
        self::assertStringNotContainsString(
            'name="access_plans[{{ $code }}][project_feedback_level]"',
            $plans
        );
    }

    public function test_project_authoring_contains_content_not_ai_policy(): void
    {
        $project = $this->viewSource('courses/partials/show/inline-authoring.blade.php');

        self::assertStringContainsString('project_requirements_ar', $project);
        foreach (['submission_max_files', 'ai_prompt', 'ai_model_type', 'temperature', 'tokens_number', 'passing_score', 'fallback_review_delay_seconds'] as $field) {
            self::assertStringNotContainsString($field, $project);
        }
    }

    #[DataProvider('courseScreens')]
    public function test_course_screens_keep_large_regions_in_partials(
        string $screen,
        array $partials,
        array $expectedContracts
    ): void {
        $view = "courses/{$screen}.blade.php";
        $screenSource = $this->viewSource($view);
        $this->assertNoInlineStyles($screenSource, $view);

        $source = $screenSource;
        foreach ($partials as $partial) {
            $partialView = "courses/partials/{$screen}/{$partial}.blade.php";
            $partialSource = $this->viewSource($partialView);

            self::assertStringContainsString(
                "@include('admin.courses.partials.{$screen}.{$partial}')",
                $screenSource
            );
            $this->assertNoInlineStyles($partialSource, $partialView);
            self::assertLessThanOrEqual(300, substr_count($partialSource, "\n") + 1, $partialView);
            $source .= $partialSource;
        }

        foreach ($expectedContracts as $contract) {
            self::assertStringContainsString($contract, $source);
        }
    }

    /** @return array<string, array{string}> */
    public static function dashboardViews(): array
    {
        return [
            'product operations' => ['product_operations.blade.php'],
            'playback operations' => ['playback_operations.blade.php'],
            'playback summary' => ['partials/playback-operations-summary.blade.php'],
            'feedback list' => ['feedback/index.blade.php'],
            'feedback details' => ['feedback/show.blade.php'],
            'project list' => ['project-submissions/index.blade.php'],
            'project review' => ['project-submissions/show.blade.php'],
            'device sessions' => ['user_sessions/index.blade.php'],
            'payment reconciliation queue' => ['payment-reconciliation-findings/index.blade.php'],
            'payment reconciliation action' => ['payment-reconciliation-findings/partials/action-form.blade.php'],
            'settings dashboard' => ['settings/index.blade.php'],
            'home dashboard' => ['home/index.blade.php'],
            'moderator workspace' => ['home/moderator.blade.php'],
            'course codes list' => ['course-codes/index.blade.php'],
            'course code details' => ['course-codes/show.blade.php'],
            'course code create' => ['course-codes/create.blade.php'],
            'course code edit' => ['course-codes/edit.blade.php'],
            'urgent tasks dashboard' => ['urgent-tasks/index.blade.php'],
            'urgent pending orders' => ['urgent-tasks/pending-orders.blade.php'],
            'urgent inactive students' => ['urgent-tasks/inactive-students.blade.php'],
            'orders list' => ['orders/index.blade.php'],
            'order details' => ['orders/show.blade.php'],
            'courses list' => ['courses/index.blade.php'],
            'course details' => ['courses/show.blade.php'],
            'course create' => ['courses/create.blade.php'],
        ];
    }

    /** @return array<string, array{string, array<int, string>, array<int, string>}> */
    public static function orderScreens(): array
    {
        return [
            'orders list' => [
                'index',
                ['statistics', 'filters', 'orders-table', 'payment-modal', 'scripts'],
                ['name="state"', 'name="payment_method"', 'name="date_from"', 'showPaymentScreenshot'],
            ],
            'order details' => [
                'show',
                ['order-information', 'actions-panel', 'screenshot-modal', 'scripts'],
                ['name="resolution"', 'name="note"', 'admin.orders.resolve-financial-review', 'showFullScreenshot'],
            ],
        ];
    }

    /** @return array<string, array{string, array<int, string>, array<int, string>}> */
    public static function courseScreens(): array
    {
        return [
            'courses list' => [
                'index',
                ['course-grid', 'scripts'],
                ['admin.courses.destroy', 'classificationFilter', 'deleteCourse'],
            ],
            'course details' => [
                'show',
                ['statistics', 'commercial-report', 'inline-authoring', 'scripts', 'course-overview', 'course-readiness'],
                ['courseStudio', 'workspace-header', 'courseAuthoringGraph'],
            ],
        ];
    }

    /** @return array<string, array{string}> */
    public static function mfaViews(): array
    {
        return [
            'setup' => ['auth/mfa-setup.blade.php'],
            'challenge' => ['auth/mfa-challenge.blade.php'],
            'recovery codes' => ['auth/mfa-backup-codes.blade.php'],
        ];
    }

    private function viewSource(string $view): string
    {
        $path = dirname(__DIR__, 2).'/resources/views/admin/'.$view;
        $source = file_get_contents($path);

        self::assertNotFalse($source);

        return $source;
    }

    private function assertNoInlineStyles(string $source, string $view): void
    {
        self::assertStringNotContainsString('<style', $source, $view);
        self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $source, $view);
        self::assertDoesNotMatchRegularExpression('/[\'\"]style[\'\"]\s*=>/i', $source, $view);
    }
}
