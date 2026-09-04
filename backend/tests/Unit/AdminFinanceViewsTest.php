<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminFinanceViewsTest extends TestCase
{
    #[DataProvider('financeViews')]
    public function test_finance_views_do_not_embed_presentation_styles(
        string $view,
        ?string $stylesheet = null
    ): void {
        $source = $this->viewSource($view);

        self::assertStringNotContainsString('<style', $source, $view);
        self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $source, $view);

        if ($stylesheet === null) {
            return;
        }

        self::assertStringContainsString("admin/assets/css/{$stylesheet}", $source, $view);

        $css = $this->stylesheetSource($stylesheet);
        self::assertStringNotContainsString('{{', $css, $stylesheet);
        self::assertStringNotContainsString('<style', $css, $stylesheet);
    }

    public function test_student_progress_views_keep_filters_navigation_and_progress_values(): void
    {
        $index = $this->viewSource('student-progress/index.blade.php');
        $show = $this->viewSource('student-progress/show.blade.php');

        self::assertStringContainsString('name="search"', $index);
        self::assertStringContainsString('name="course_id"', $index);
        self::assertStringContainsString("route('admin.student-progress.show'", $index);
        self::assertStringContainsString("route('admin.student-progress.index')", $show);
        self::assertStringContainsString('{{ $users->links() }}', $index);

        self::assertStringContainsString('data-progress="{{ $userProgress', $index);
        self::assertStringContainsString('data-progress="{{ $courseProgress', $show);
        self::assertStringContainsString(".data('progress')", $index);
        self::assertStringContainsString(".data('progress')", $show);
    }

    /** @return array<string, array{string, ?string}> */
    public static function financeViews(): array
    {
        return [
            'student progress list' => ['student-progress/index.blade.php', 'student-progress-index.css'],
            'student progress details' => ['student-progress/show.blade.php', 'student-progress-show.css'],
        ];
    }

    private function viewSource(string $view): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/'.$view);

        self::assertNotFalse($source, $view);

        return $source;
    }

    private function stylesheetSource(string $stylesheet): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/public/admin/assets/css/'.$stylesheet);

        self::assertNotFalse($source, $stylesheet);

        return $source;
    }
}
