<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseCode;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\User;
use App\Services\AdminCoursePreviewService;
use App\Services\CoursePresentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

final class AdminCoursePreviewOwnershipTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith([null, 'login'])]
    #[TestWith(['client', null])]
    #[TestWith(['moderator', 'admin.mfa.setup'])]
    public function test_preview_remains_staff_only_and_requires_mfa(?string $role, ?string $redirect): void
    {
        $course = $this->course();
        $url = route('admin.courses.student-preview', $course);
        if ($role !== null) {
            $user = new User();
            $user->forceFill([
                'name_ar' => 'المستخدم', 'email' => $role.'-preview-guard@example.test',
                'role' => $role, 'active' => true,
            ])->save();
            $this->actingAs($user, 'web');
        }
        $response = $this->get($url);
        if ($redirect === null) $response->assertForbidden();
        else $response->assertRedirect(route($redirect));
        $this->assertNoLearningWrites();
    }

    public function test_preparation_is_read_only_without_http_request_actor_or_resource_presentation(): void
    {
        $course = $this->course();
        $this->app->bind(CoursePresentationService::class, static function (): never {
            throw new \LogicException('Preparation must not resolve HTTP resource presentation.');
        });
        DB::enableQueryLog();
        DB::flushQueryLog();
        $data = app(AdminCoursePreviewService::class)->prepare($course, null);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        self::assertNull($data['error']);
        self::assertSame('basic', $data['selectedPlan']['code']);
        self::assertSame($course->id, $data['previewCourse']->id);
        self::assertSame($course->id, $data['publishedDeviceCourseId']);
        self::assertArrayNotHasKey('previewPayload', $data);
        self::assertNotEmpty($queries);
        foreach ($queries as $query) {
            self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query['query']);
        }
        $this->assertNoLearningWrites();
    }

    public function test_http_preview_serializes_the_selected_working_draft_and_keeps_device_link_canonical(): void
    {
        $canonical = $this->course();
        $draft = $this->course(true);
        CourseAuthoringRevision::query()->create([
            'canonical_course_id' => $canonical->id,
            'revision_course_id' => $draft->id,
            'base_authoring_version' => 4,
            'status' => CourseAuthoringRevision::DRAFT,
            'active_slot' => 'course-draft:'.$canonical->id,
            'clone_key' => (string) Str::uuid(),
        ]);
        $this->asModerator();

        $response = $this->get(route('admin.courses.student-preview', [$canonical, 'plan' => ' BASIC ']))
            ->assertOk()
            ->assertViewHas('previewCourse', fn (Course $value): bool => $value->id === $draft->id)
            ->assertViewHas('publishedDeviceCourseId', $canonical->id)
            ->assertViewHas('selectedPlan', fn (array $plan): bool => $plan['code'] === 'basic')
            ->assertViewHas('previewPayload', fn (array $payload): bool => $payload['projects_available'] === false)
            ->assertSee('rokn://course/'.$canonical->id, false);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame(2, Course::query()->count());
        self::assertSame(1, CourseAuthoringRevision::query()->count());
        self::assertSame(4, (int) $canonical->fresh()->authoring_version);
        self::assertSame(4, (int) $draft->fresh()->authoring_version);
        $this->assertNoLearningWrites();
    }

    public function test_http_grant_preview_and_invalid_plan_do_not_create_learning_access(): void
    {
        $course = $this->course(true);
        (new CourseCode())->forceFill([
            'tenant_id' => 1, 'type' => 'course', 'course_id' => $course->id,
            'code' => 'preview-only-grant', 'is_grant' => true,
            'is_active' => true, 'max_uses' => 10, 'used_count' => 0,
        ])->save();
        $this->asModerator();
        $this->get(route('admin.courses.student-preview', [$course, 'plan' => 'grant']))
            ->assertOk()
            ->assertViewHas('publishedDeviceCourseId', null)
            ->assertViewHas('selectedPlan', fn (array $plan): bool => $plan['code'] === 'grant'
                && $plan['projects_enabled'] === false && $plan['certificate_enabled'] === false)
            ->assertViewHas('previewPayload', fn (array $payload): bool => $payload['projects_available'] === false);
        $this->get(route('admin.courses.student-preview', [$course, 'plan' => 'missing']))->assertStatus(422);
        self::assertSame(0, (int) CourseCode::query()->firstOrFail()->used_count);
        self::assertSame(0, CourseAuthoringRevision::query()->count());
        $this->assertNoLearningWrites();
    }

    private function course(bool $draft = false): Course
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1, 'name_ar' => $draft ? 'المسودة' : 'المنشور',
            'price' => 400, 'is_coming_soon' => $draft, 'is_catalog_visible' => !$draft,
            'authoring_version' => 4, 'last_published_authoring_version' => $draft ? null : 4,
            'published_at' => $draft ? null : now(), 'certificate_text_template_key' => 'completion',
        ])->save();
        $course->accessPlans()->create([
            'code' => 'basic', 'name_ar' => 'Basic', 'name_en' => 'Basic',
            'price_coins' => 400, 'minimum_paid_coins' => 0,
            'chat_enabled' => false, 'certificate_enabled' => false,
            'projects_enabled' => false, 'project_feedback_level' => 'pass_only', 'is_active' => true,
        ]);
        $module = CourseModule::query()->create([
            'course_id' => $course->id, 'title_ar' => 'الوحدة', 'order' => 1,
        ]);
        $lesson = Lesson::query()->create([
            'list_id' => $course->id, 'title_ar' => 'الدرس', 'duration_minutes' => 1,
        ]);
        CourseSection::query()->create([
            'course_id' => $course->id, 'module_id' => $module->id,
            'title_ar' => 'الدرس', 'section_type' => 'lesson',
            'sectionable_type' => Lesson::class, 'sectionable_id' => $lesson->id, 'order' => 1,
        ]);

        return $course;
    }

    private function asModerator(): void
    {
        $moderator = new User();
        $moderator->forceFill([
            'name_ar' => 'المشرف', 'email' => 'preview-owner@example.test',
            'role' => 'moderator', 'active' => true,
        ])->save();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($moderator, 'web');
    }

    private function assertNoLearningWrites(): void
    {
        self::assertSame(0, CourseEnrollment::query()->count());
        self::assertSame(0, Order::query()->count());
        self::assertSame(0, Certificate::query()->count());
        self::assertSame(0, DB::table('student_section_progress')->count());
    }
}
