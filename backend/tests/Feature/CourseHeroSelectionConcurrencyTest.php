<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Classification;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\User;
use App\Services\CourseHeroSelectionService;
use App\Services\CoursePlanAuthoringService;
use App\Services\CoursePublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

    public function test_first_publication_hands_its_owned_revision_to_publish_and_hero_stages(): void
    {
        $oldHero = $this->publishedCourse(true, 4);
        $course = $this->readyComingSoonCourse(7);
        $this->actingAsAdministrator();

        $this->patch(route('admin.courses.update', $course), [
            'authoring_version' => 7,
            'publishing_intent' => 'publish',
            'is_catalog_visible' => '1',
            'is_main_course' => '1',
        ])
            ->assertRedirect(route('admin.courses.show', $course))
            ->assertSessionHas('success', 'تم تحديث الكورس بنجاح.')
            ->assertSessionMissing('error');

        // Each stage revision-checks the course. A stage that re-read or reused
        // a stale revision would end in publish_failed or hero_failed (an
        // error flash) instead of this clean result.
        $published = $course->fresh();
        self::assertFalse((bool) $published->is_coming_soon);
        self::assertTrue((bool) $published->is_main_course);
        self::assertGreaterThan(7, (int) $published->authoring_version);
        self::assertFalse((bool) $oldHero->fresh()->is_main_course);
        self::assertSame(1, Course::query()->where('is_main_course', true)->count());
    }

    public function test_course_update_hands_its_owned_revision_to_the_catalog_card_stage(): void
    {
        $course = $this->publishedCourse(false, 2);
        $course->forceFill([
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
            'description_ar' => 'وصف بطاقة قريبًا',
            'image' => 'courses/cover.webp',
        ])->save();
        $course->teachers()->attach($this->dashboardUser('teacher'));
        $course->classifications()->attach(
            Classification::query()->create(['name_ar' => 'تصنيف', 'name_en' => 'Row'])
        );
        $this->actingAsAdministrator();

        $this->patch(route('admin.courses.update', $course), [
            'authoring_version' => 2,
            'is_catalog_visible' => '1',
        ])
            ->assertRedirect(route('admin.courses.show', $course))
            ->assertSessionHas('success', 'تم تحديث الكورس بنجاح.')
            ->assertSessionMissing('error');

        self::assertTrue((bool) $course->fresh()->is_catalog_visible);
        self::assertTrue((bool) $course->fresh()->is_coming_soon);
        // Edit and catalog card each advance the revision the stage before produced.
        self::assertSame(4, (int) $course->fresh()->authoring_version);
    }

    private function readyComingSoonCourse(int $version): Course
    {
        Http::preventStrayRequests();
        Storage::fake('public');
        $course = $this->publishedCourse(false, $version);
        $course->forceFill([
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
            'description_ar' => 'وصف الكورس',
            'price' => 800,
            'certificate_text_template_key' => 'completion',
            'attachment_prompt_enabled' => false,
        ])->save();
        $course->teachers()->attach($this->dashboardUser('teacher'));
        $course->classifications()->attach(
            Classification::query()->create(['name_ar' => 'التصنيف', 'name_en' => 'Category'])
        );
        Storage::disk('public')->put('courses/ready-cover.jpg', 'cover bytes');
        $course->allPhotos()->create(['path' => 'courses/ready-cover.jpg', 'type' => 'featured']);
        app(CoursePlanAuthoringService::class)->createDefaults($course);
        $module = $course->modules()->create(['title_ar' => 'الوحدة', 'order' => 1]);
        $guid = '33333333-3333-4333-8333-333333333333';
        $lesson = Lesson::query()->create([
            'list_id' => $course->id, 'title_ar' => 'المقطع',
            'video_source_type' => 'bunny', 'bunny_video_id' => $guid,
        ]);
        LessonMediaState::query()->create([
            'lesson_id' => $lesson->id, 'provider' => 'bunny', 'provider_media_id' => $guid,
            'status' => 'ready', 'duration_seconds' => 60,
            'integrity_status' => 'healthy', 'last_reconciled_at' => now(),
        ]);
        $course->sections()->create([
            'title_ar' => 'المقطع', 'module_id' => $module->id, 'order' => 1,
            'section_type' => 'lesson', 'sectionable_type' => Lesson::class,
            'sectionable_id' => $lesson->id,
        ]);
        $audit = app(CoursePublishingService::class)->audit($course->fresh());
        self::assertTrue($audit['ready'], implode("\n", $audit['issues']));

        return $course;
    }

    private function actingAsAdministrator(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($this->dashboardUser('admin'), 'web');
    }

    private function dashboardUser(string $role): User
    {
        return User::query()->forceCreate([
            'name_ar' => 'مستخدم '.$role,
            'email' => $role.'-hero@rokn.test',
            'role' => $role,
            'active' => true,
        ]);
    }

    private function publishedCourse(bool $main, int $version): Course
    {
        $course = new Course;
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
