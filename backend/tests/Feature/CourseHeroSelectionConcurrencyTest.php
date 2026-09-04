<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Services\CourseHeroSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CourseHeroSelectionConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_switching_the_home_hero_updates_all_flags_in_one_revision_checked_operation(): void
    {
        $oldHero = $this->publishedCourse(true, 4);
        $newHero = $this->publishedCourse(false, 7);

        app(CourseHeroSelectionService::class)->synchronize($newHero, 7, true);

        self::assertFalse((bool) $oldHero->fresh()->is_main_course);
        self::assertSame(5, (int) $oldHero->fresh()->authoring_version);
        self::assertTrue((bool) $newHero->fresh()->is_main_course);
        self::assertSame(8, (int) $newHero->fresh()->authoring_version);
        self::assertSame(1, Course::query()->where('is_main_course', true)->count());
    }

    public function test_stale_hero_request_cannot_overwrite_a_newer_authoring_revision(): void
    {
        $oldHero = $this->publishedCourse(true, 3);
        $candidate = $this->publishedCourse(false, 6);
        $candidate->forceFill(['authoring_version' => 7])->save();

        try {
            app(CourseHeroSelectionService::class)->synchronize($candidate, 6, true);
            self::fail('Expected stale hero selection to be rejected.');
        } catch (ValidationException $exception) {
            self::assertSame(409, $exception->status);
        }

        self::assertTrue((bool) $oldHero->fresh()->is_main_course);
        self::assertFalse((bool) $candidate->fresh()->is_main_course);
        self::assertSame(1, Course::query()->where('is_main_course', true)->count());
    }

    public function test_course_update_orchestration_passes_its_owned_revision_between_stages(): void
    {
        $source = file_get_contents(
            app_path('Services/AdminCourseAuthoringService.php')
        );

        self::assertIsString($source);
        $source = str_replace("\r\n", "\n", $source);
        self::assertStringContainsString(
            '$ownedVersion = $this->authoring->advance($locked);',
            $source
        );
        self::assertStringContainsString(
            '$publish = $this->publishDirectly($course, (int) $ownedVersion, $catalogVisible);',
            $source
        );
        self::assertStringContainsString(
            '$catalog = $this->publishCatalogCard($fresh, (int) $ownedVersion);',
            $source
        );
        self::assertStringContainsString(
            '$this->heroSelection->synchronize($course, (int) $ownedVersion, $heroRequested);',
            $source
        );
        self::assertStringNotContainsString(
            '$heroExpectedVersion = (int) $heroCourse->authoring_version;',
            $source
        );
    }

    private function publishedCourse(bool $main, int $version): Course
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'كورس '.str()->uuid(),
            'is_coming_soon' => false,
            'is_catalog_visible' => true,
            'is_main_course' => $main,
            'authoring_version' => $version,
        ])->save();

        return $course;
    }
}
