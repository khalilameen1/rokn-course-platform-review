<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\AppFrontNameSpace;
use App\Http\Middleware\WebsiteVisitorCount;
use App\Models\Classification;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CourseDiscoveryArabicTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([AppFrontNameSpace::class, WebsiteVisitorCount::class]);
    }

    public function test_arabic_variants_find_the_same_published_course(): void
    {
        [$course] = $this->publishedCourse('إدارة الأعمال ٣');

        $this->getJson('/api/v1/search/courses?q=اداره%20الاعمال%203')
            ->assertOk()
            ->assertJsonPath('data.items.0.course_id', $course->id);
    }

    public function test_search_filters_keep_the_public_catalogue_query_executable(): void
    {
        [$course] = $this->publishedCourse('تصميم الشعارات');
        $classificationId = (int) $course->classifications()->value('classifications.id');

        $this->getJson('/api/v1/search/courses?' . http_build_query([
            'q' => 'تصميم',
            'classification_id' => $classificationId,
        ]))
            ->assertOk()
            ->assertJsonPath('data.items.0.course_id', $course->id);
    }

    public function test_retired_course_leaves_discovery_and_details(): void
    {
        [$course] = $this->publishedCourse('صناعة المحتوى');

        $this->getJson('/api/v1/search/courses?q=المحتوي')
            ->assertOk()
            ->assertJsonPath('data.items.0.course_id', $course->id);

        $course->delete();

        $this->getJson('/api/v1/search/courses?q=المحتوي')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
        $this->getJson("/api/v1/courses/{$course->id}/details")
            ->assertNotFound();
    }

    public function test_administrator_restores_archive_as_hidden_draft_without_losing_cover(): void
    {
        [$course] = $this->publishedCourse('كورس قابل للاستعادة');
        $photo = $course->allPhotos()->create([
            'path' => 'courses/restorable-cover.jpg',
            'type' => 'featured',
        ]);
        $course->delete();

        $this->assertDatabaseHas('photos', ['id' => $photo->id]);

        $admin = new User();
        $admin->forceFill([
            'name' => 'Rokn Admin',
            'email' => 'admin-'.str()->uuid().'@example.test',
            'role' => 'admin',
            'active' => true,
        ])->save();
        $this->withoutMiddleware();
        $this->actingAs($admin)
            ->post("/dashboard/courses/{$course->id}/restore")
            ->assertRedirect(route('admin.courses.show', $course->id));

        $restored = Course::query()->findOrFail($course->id);
        self::assertFalse((bool) $restored->is_catalog_visible);
        self::assertTrue((bool) $restored->is_coming_soon);
        self::assertFalse((bool) $restored->is_main_course);
        $this->assertDatabaseHas('photos', ['id' => $photo->id]);
    }

    /** @return array{Course, Lesson} */
    private function publishedCourse(string $title): array
    {
        $teacher = new User();
        $teacher->forceFill([
            'name' => 'أحمد أمين',
            'email' => 'teacher-'.str()->uuid().'@example.test',
            'role' => 'teacher',
            'active' => true,
        ])->save();

        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => $title,
            'name_en' => 'Business Course',
            'description_ar' => 'كورس عربي عملي',
            'description_en' => 'Practical course',
            'teacher_id' => $teacher->id,
            'image' => '/images/course-cover.jpg',
            'is_catalog_visible' => true,
            'is_coming_soon' => false,
            'home_sort_order' => 10,
        ])->save();

        $classification = Classification::query()->create([
            'name_ar' => 'إدارة',
            'name_en' => 'Business',
            'show_on_home' => true,
            'home_order' => 10,
        ]);
        $course->classifications()->attach($classification->id);
        CourseAccessPlan::query()->create([
            'course_id' => $course->id,
            'code' => CourseAccessPlan::BASIC,
            'name_ar' => 'الأساسية',
            'price_coins' => 100,
            'is_active' => true,
        ]);

        $lesson = Lesson::query()->create([
            'list_id' => $course->id,
            'title' => 'المقطع الأول',
            'title_ar' => 'المقطع الأول',
            'duration_minutes' => 5,
            'is_opened' => true,
        ]);
        $module = CourseModule::query()->create([
            'course_id' => $course->id,
            'title_ar' => 'محتوى الكورس',
            'order' => 1,
        ]);
        DB::table('course_sections')->insert([
            'course_id' => $course->id,
            'module_id' => $module->id,
            'title' => 'المقطع الأول',
            'title_ar' => 'المقطع الأول',
            'section_type' => 'lesson',
            'sectionable_type' => Lesson::class,
            'sectionable_id' => $lesson->id,
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$course, $lesson];
    }
}
