<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminCourseOperationsViewTest extends TestCase
{
    #[DataProvider('interactiveViews')]
    public function test_course_operations_views_do_not_reintroduce_inline_styles(
        string $view,
        bool $isPage = true
    ): void {
        $source = $this->viewSource($view);

        self::assertStringNotContainsString('<style', $source, $view);
        self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $source, $view);
        self::assertDoesNotMatchRegularExpression('/[\'\"]style[\'\"]\s*=>/i', $source, $view);
        if ($isPage) {
            self::assertStringContainsString('admin-page', $source, $view);
        }
    }

    public function test_course_module_authoring_has_no_parallel_page(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Http/Controllers/Admin/CourseModuleController.php');

        self::assertIsString($controller);
        self::assertFileDoesNotExist($root.'/resources/views/admin/course-modules/create.blade.php');
        self::assertFileDoesNotExist($root.'/resources/views/admin/course-modules/edit.blade.php');
        self::assertFileDoesNotExist($root.'/resources/views/admin/course-modules/partials/fields.blade.php');
        self::assertFileDoesNotExist($root.'/public/admin/assets/css/course-modules.css');
        self::assertSame(2, substr_count($controller, 'return $this->authoringRedirect($course);'));
        self::assertStringNotContainsString("view('admin.course-modules.", $controller);
    }

    public function test_teacher_editor_keeps_account_and_profile_fields(): void
    {
        $form = $this->viewSource('teachers/_form.blade.php');

        foreach ([
            'name="name_ar"',
            'name="email"',
            'name="phone"',
            'name="password"',
            'name="password_confirmation"',
            'name="bio_ar"',
            'name="image"',
            'name="active"',
        ] as $field) {
            self::assertStringContainsString($field, $form);
        }
    }

    /** @return array<string, array{string, bool}> */
    public static function interactiveViews(): array
    {
        return [
            'teacher list' => ['teachers/index.blade.php', true],
            'teacher create' => ['teachers/create.blade.php', true],
            'teacher edit' => ['teachers/edit.blade.php', true],
            'teacher details' => ['teachers/show.blade.php', true],
            'teacher fields' => ['teachers/_form.blade.php', false],
            'notifications list' => ['notifications/index.blade.php', true],
            'notifications create' => ['notifications/create.blade.php', true],
            'classifications list' => ['classifications/index.blade.php', true],
            'classifications create' => ['classifications/create.blade.php', true],
            'classifications edit' => ['classifications/edit.blade.php', true],
            'levels list' => ['levels/index.blade.php', true],
            'levels create' => ['levels/create.blade.php', true],
            'levels edit' => ['levels/edit.blade.php', true],
        ];
    }

    private function viewSource(string $view): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/'.$view);
        self::assertNotFalse($source);

        return $source;
    }
}
