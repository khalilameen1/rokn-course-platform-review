<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Http\Middleware\AppFrontNameSpace;
use App\Http\Middleware\WebsiteVisitorCount;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\User;
use App\Services\CourseSectionSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LearningDashboardEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_my_corner_returns_multiple_enrolled_courses_with_independent_module_orders(): void
    {
        $this->withoutMiddleware([AppFrontNameSpace::class, WebsiteVisitorCount::class]);
        $student = $this->user('client');
        $teacher = $this->user('teacher');
        $expected = [];

        foreach (['Grease Pencil', 'تعلم البرمجة'] as $title) {
            $course = new Course();
            $course->forceFill([
                'tenant_id' => 1,
                'teacher_id' => $teacher->id,
                'name_ar' => $title,
                'name_en' => $title,
                'is_coming_soon' => false,
                'is_catalog_visible' => true,
                'price' => 0,
            ])->save();
            $firstLessonId = null;
            // Deliberately insert the later module first. Each course must
            // follow its own module order, not the section's local order.
            foreach ([2, 1] as $moduleOrder) {
                $moduleId = DB::table('course_modules')->insertGetId([
                    'course_id' => $course->id,
                    'title' => "Module {$moduleOrder}",
                    'order' => $moduleOrder,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $lesson = Lesson::create([
                    'list_id' => $course->id,
                    'title' => "Lesson {$moduleOrder}",
                    'title_ar' => "Lesson {$moduleOrder}",
                ]);
                DB::table('course_sections')->insert([
                    'course_id' => $course->id,
                    'module_id' => $moduleId,
                    'title' => "Lesson {$moduleOrder}",
                    'section_type' => 'lesson',
                    'sectionable_type' => Lesson::class,
                    'sectionable_id' => $lesson->id,
                    'order' => 3 - $moduleOrder,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($moduleOrder === 1) $firstLessonId = $lesson->id;
            }
            DB::table('course_enrollments')->insert([
                'tenant_id' => 1,
                'user_id' => $student->id,
                'course_id' => $course->id,
                'is_active' => true,
                'access_granted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $expected[$course->id] = ['title' => $title, 'lesson' => $firstLessonId];
        }

        $response = $this->actingAs($student, 'api')
            ->getJson('/api/v1/learning/courses?per_page=100')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.pagination.has_more', false);

        foreach ($response->json('data.items') as $item) {
            self::assertSame($expected[$item['course_id']]['title'], $item['title']);
            self::assertSame(2, $item['total_sections']);
            self::assertSame(0, $item['completed_sections']);
            self::assertFalse($item['learning_started']);
            self::assertSame($expected[$item['course_id']]['lesson'], $item['next_section']['id']);
            self::assertSame('lesson', $item['next_section']['type']);
            self::assertFalse($item['resume']['available']);
        }

        $firstPage = $this->getJson('/api/v1/learning/courses?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.pagination.has_more', true);
        $secondPage = $this->getJson('/api/v1/learning/courses?per_page=1&cursor='.
            urlencode($firstPage->json('data.pagination.next_cursor')))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.pagination.has_more', false);
        self::assertEqualsCanonicalizing(array_keys($expected), [
            $firstPage->json('data.items.0.course_id'),
            $secondPage->json('data.items.0.course_id'),
        ]);

        $this->actingAs($teacher, 'api')->getJson('/api/v1/learning/courses')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    public function test_missing_module_is_still_rejected_in_a_multi_course_read(): void
    {
        $section = new CourseSection();
        $section->forceFill([
            'id' => 1,
            'course_id' => 1,
            'module_id' => 999,
            'section_type' => 'lesson',
            'sectionable_type' => Lesson::class,
            'sectionable_id' => 1,
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A learning section references a missing course module.');
        app(CourseSectionSequenceService::class)->learningByCourse(collect([$section]));
    }

    private function user(string $role): User
    {
        $user = new User();
        $user->forceFill([
            'name' => 'Dashboard learner',
            'email' => 'dashboard-'.str()->uuid().'@example.test',
            'role' => $role,
            'active' => true,
            'watch_history_enabled' => true,
            'social_provider' => 'google',
            'social_id' => (string) str()->uuid(),
        ])->save();

        return $user;
    }
}
