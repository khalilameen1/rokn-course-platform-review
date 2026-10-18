<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RendersAdminPages;
use Tests\TestCase;

/** Formerly tests/Unit/AdminFinanceViewsTest (source reads of the student-progress views). */
final class AdminStudentProgressViewsTest extends TestCase
{
    use RefreshDatabase;
    use RendersAdminPages;

    public function test_student_progress_pages_load_their_own_stylesheets_without_embedded_styles(): void
    {
        [$admin, $student] = $this->records();

        foreach ([
            'student-progress-index.css' => route('admin.student-progress.index'),
            'student-progress-show.css' => route('admin.student-progress.show', $student),
        ] as $stylesheet => $url) {
            $html = (string) $this->adminGet($admin, $url)->assertOk()->getContent();

            $this->assertNoInlineStyleAttributes($html, $url);
            self::assertSame(0, $this->styleBlockCount($html), $url);
            $this->assertLinksStylesheet($html, $stylesheet, $url);
            // A static stylesheet: nothing in it is left for Blade to evaluate.
            self::assertStringNotContainsString('{{', (string) file_get_contents(public_path('admin/assets/css/'.$stylesheet)));
        }
    }

    public function test_student_progress_views_keep_filters_navigation_and_progress_values(): void
    {
        foreach (range(1, 15) as $index) {
            $this->dashboardUser('client', "progress-filler-{$index}@rokn.test");
        }
        [$admin, $student] = $this->records();

        $index = $this->dom((string) $this->adminGet($admin, route('admin.student-progress.index'))->assertOk()->getContent());
        $filter = '//form[@method="GET" and @action="'.route('admin.student-progress.index').'"]';
        self::assertSame(1, $index->query($filter.'//input[@name="search"]')->length);
        self::assertSame(1, $index->query($filter.'//select[@name="course_id"]')->length);
        self::assertGreaterThan(0, $index->query('//a[@href="'.route('admin.student-progress.show', $student->id).'"]')->length);
        self::assertGreaterThan(0, $index->query('//a[contains(@href, "page=2")]')->length, 'The learner list paginates.');

        $show = $this->dom((string) $this->adminGet($admin, route('admin.student-progress.show', $student))->assertOk()->getContent());
        self::assertGreaterThan(0, $show->query('//a[@href="'.route('admin.student-progress.index').'"]')->length);
        foreach ([$index, $show] as $page) {
            $bars = $page->query('//*[@data-progress]');
            self::assertGreaterThan(0, $bars->length);
            foreach ($bars as $bar) {
                $value = $bar->attributes->getNamedItem('data-progress')->nodeValue;
                self::assertTrue(is_numeric($value) && $value >= 0 && $value <= 100, (string) $value);
            }
            // The bars are sized from data-progress by the page script, not inline styles.
            self::assertStringContainsString("data('progress')", (string) $page->evaluate('string(//body)'));
        }
    }

    /** @return array{User, User} */
    private function records(): array
    {
        $admin = $this->dashboardUser('admin');
        $student = $this->dashboardUser('client', 'progress-learner@rokn.test');
        $course = Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'كورس التقدم', 'price' => 100,
            'is_coming_soon' => false, 'is_catalog_visible' => true, 'authoring_version' => 1,
        ]);
        CourseEnrollment::query()->forceCreate([
            'tenant_id' => 1, 'user_id' => $student->id, 'course_id' => $course->id,
            'is_active' => true, 'enrolled_at' => now(), 'access_granted_at' => now(),
        ]);

        return [$admin, $student];
    }
}
