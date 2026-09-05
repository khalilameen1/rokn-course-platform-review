<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseRating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminCourseDraftRatingPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_moderator_draft_preview_uses_the_published_courses_rating(): void
    {
        $moderator = $this->user('moderator', 'rating-preview-moderator@example.test');
        $firstStudent = $this->user('client', 'rating-preview-one@example.test');
        $secondStudent = $this->user('client', 'rating-preview-two@example.test');
        $canonical = $this->course('الكورس المنشور', false, true);
        $draft = $this->course('المسودة الجارية', true, false);

        CourseAuthoringRevision::query()->create([
            'canonical_course_id' => $canonical->id,
            'revision_course_id' => $draft->id,
            'base_authoring_version' => 4,
            'status' => CourseAuthoringRevision::DRAFT,
            'active_slot' => 'course-draft:'.$canonical->id,
            'clone_key' => (string) Str::uuid(),
        ]);
        $this->rating($canonical, $firstStudent, 4);
        $this->rating($canonical, $secondStudent, 5);

        $this->withoutMiddleware(RequireAdminMfa::class);
        $response = $this->actingAs($moderator, 'web')
            ->get(route('admin.courses.show', $canonical))
            ->assertOk();

        $response->assertSee('المسودة الجارية');
        $response->assertSee('4.5 · 2');
        $response->assertDontSee('لا تقييمات');
        $response->assertDontSee('الطلاب والدخل');
        $response->assertDontSee('إجمالي نقدي مؤكد');
    }

    private function user(string $role, string $email): User
    {
        $user = new User();
        $user->forceFill([
            'name_ar' => $role === 'moderator' ? 'محرر المحتوى' : 'طالب ركن',
            'email' => $email,
            'role' => $role,
            'active' => true,
        ])->save();

        return $user;
    }

    private function course(string $name, bool $draft, bool $visible): Course
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => $name,
            'price' => 500,
            'is_coming_soon' => $draft,
            'is_catalog_visible' => $visible,
            'authoring_version' => 4,
            'last_published_authoring_version' => $draft ? null : 4,
            'published_at' => $draft ? null : now(),
        ])->save();

        return $course;
    }

    private function rating(Course $course, User $student, int $value): void
    {
        $rating = new CourseRating();
        $rating->forceFill([
            'course_id' => $course->id,
            'user_id' => $student->id,
            'rating' => $value,
            'version' => 1,
        ])->save();
    }
}
