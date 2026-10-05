<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Project;
use App\Models\StudentNotification;
use App\Models\User;
use App\Services\CourseAccessPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Real migrated HTTP/resource/cursor path. */
final class HomeCourseNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()->set('app.locale', 'ar');
        app()->setLocale('ar');
        // Symfony test requests default to an English Accept-Language header;
        // use the header sent by the real mobile API request policy.
        $this->withHeader('Accept-Language', 'ar');
    }

    public function test_unrelated_inbox_rows_do_not_hide_an_older_course_campaign(): void
    {
        $student = $this->student();
        $course = $this->course();
        $campaign = $this->notification($student, $course, 'course_promotion', ['created_at' => now()->subDay()]);
        for ($index = 0; $index < 35; $index++) {
            $this->notification($student, null, 'coin_reward');
        }

        $response = $this->actingAs($student, 'api')->getJson($this->homeUrl())->assertOk()
            ->assertJsonPath('surface', 'home')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $campaign->id)
            ->assertJsonPath('data.0.home_course.id', $course->id)
            ->assertJsonPath('data.0.home_course.title', 'عنوان الكورس الحالي')
            ->assertJsonPath('data.0.home_course.image_url', 'https://cdn.example/current-cover.jpg');
        self::assertFalse($response->json('pagination.has_more_pages'));
        $this->actingAs($student, 'api')->getJson('/api/v1/notifications?per_page=30')->assertOk()
            ->assertJsonCount(30, 'data')->assertJsonMissingPath('data.0.home_course')
            ->assertJsonMissingPath('surface');
        self::assertFalse($campaign->fresh()->is_read);
    }

    public function test_home_uses_live_course_art_while_inbox_keeps_authored_delivery_copy(): void
    {
        $student = $this->student();
        $course = $this->course();
        $row = $this->notification($student, $course);
        $this->actingAs($student, 'api')->getJson($this->homeUrl())->assertOk()
            ->assertJsonPath('data.0.title_ar', 'عنوان الحملة')
            ->assertJsonPath('data.0.image_url', 'https://cdn.example/campaign-art.jpg')
            ->assertJsonPath('data.0.home_course.title', 'عنوان الكورس الحالي')
            ->assertJsonPath('data.0.home_course.image_url', 'https://cdn.example/current-cover.jpg');
        $course->update(['name_ar' => 'عنوان معدّل', 'image' => 'https://cdn.example/updated-cover.jpg']);
        $this->getJson($this->homeUrl())->assertOk()
            ->assertJsonPath('data.0.home_course.title', 'عنوان معدّل')
            ->assertJsonPath('data.0.home_course.image_url', 'https://cdn.example/updated-cover.jpg');
        $this->getJson('/api/v1/notifications/'.$row->id)->assertOk()
            ->assertJsonPath('data.title_ar', 'عنوان الحملة')->assertJsonMissingPath('data.home_course');
    }

    public function test_read_hidden_draft_deleted_and_non_course_rows_are_not_home_candidates(): void
    {
        $student = $this->student();
        $other = $this->student();
        $valid = $this->course();
        $wanted = $this->notification($student, $valid, 'course_recommendation');
        $this->notification($student, $valid, 'new_course', ['is_read' => true, 'read_at' => now()]);
        $this->notification($other, $valid);
        foreach (['hidden', 'draft', 'deleted', 'no-plan', 'no-sections'] as $state) {
            $course = $this->course();
            $this->notification($student, $course);
            match ($state) {
                'hidden' => $course->update(['is_catalog_visible' => false]),
                'draft' => $course->update(['is_coming_soon' => true]),
                'deleted' => $course->delete(),
                'no-plan' => $course->accessPlans()->update(['is_active' => false]),
                'no-sections' => $course->sections()->delete(),
            };
        }
        $this->notification($student, $valid, 'certificate_ready');
        $this->notification($student, null, 'new_course', ['link' => 'rokn://course/'.$valid->id]);
        $this->actingAs($student, 'api')->getJson($this->homeUrl())->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $wanted->id);
    }

    public function test_home_course_title_follows_request_language_without_rewriting_authored_copy(): void
    {
        $student = $this->student();
        $course = $this->course();
        $this->notification($student, $course);

        $this->actingAs($student, 'api')->withHeader('Accept-Language', 'en-US')
            ->getJson($this->homeUrl())->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('data.0.home_course.title', 'Current course title')
            ->assertJsonPath('data.0.title_ar', 'عنوان الحملة');
        $this->withHeader('Accept-Language', 'ar-EG')
            ->getJson($this->homeUrl())->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('data.0.home_course.title', 'عنوان الكورس الحالي')
            ->assertJsonPath('data.0.title_en', 'Campaign title');
    }

    public function test_owned_page_retains_cursor_and_the_next_page_can_supply_an_unowned_course(): void
    {
        $student = $this->student();
        $olderCourse = $this->course();
        $older = $this->notification($student, $olderCourse, 'new_course', ['created_at' => now()->subDay()]);
        $owned = $this->course();
        $this->notification($student, $owned);
        $plan = $owned->accessPlans()->firstOrFail();
        CourseEnrollment::query()->forceCreate([
            ...$this->legacyTenant('course_enrollments'),
            'user_id' => $student->id, 'course_id' => $owned->id,
            'access_plan_id' => $plan->id,
            'access_plan_snapshot' => app(CourseAccessPlanService::class)->snapshot($plan),
            'is_active' => true, 'enrolled_at' => now(), 'access_granted_at' => now(),
        ]);
        $first = $this->actingAs($student, 'api')->getJson($this->homeUrl(1))->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('pagination.has_more_pages', true);
        $cursor = $first->json('pagination.next_cursor');
        self::assertIsString($cursor);
        $this->getJson($this->homeUrl(1).'&cursor='.urlencode($cursor))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $older->id)
            ->assertJsonPath('pagination.has_more_pages', false);
    }

    public function test_expired_access_can_receive_a_new_offer_but_current_access_cannot(): void
    {
        $student = $this->student();
        $course = $this->course();
        $this->notification($student, $course);
        $enrollment = CourseEnrollment::query()->forceCreate([
            ...$this->legacyTenant('course_enrollments'),
            'user_id' => $student->id, 'course_id' => $course->id,
            'is_active' => true, 'expires_at' => now()->subMinute(), 'enrolled_at' => now()->subDay(),
        ]);
        $this->actingAs($student, 'api')->getJson($this->homeUrl())->assertOk()->assertJsonCount(1, 'data');
        $enrollment->update(['expires_at' => now()->addDay()]);
        $this->getJson($this->homeUrl())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_home_slice_is_authenticated_and_surface_is_explicit(): void
    {
        $this->getJson($this->homeUrl())->assertUnauthorized();
        $this->actingAs($this->student(), 'api')
            ->getJson('/api/v1/notifications?surface=anything')->assertUnprocessable();
        $this->getJson($this->homeUrl())->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('surface', 'home');
        $this->getJson('/api/v1/notifications?surface=home')->assertUnprocessable();
    }

    public function test_disabling_offers_stops_old_marketing_popups_without_erasing_the_inbox(): void
    {
        $student = $this->student();
        $course = $this->course();
        $row = $this->notification($student, $course);
        $this->actingAs($student, 'api')->getJson($this->homeUrl())->assertOk()->assertJsonCount(1, 'data');
        $student->update(['marketing_notifications_enabled' => false]);
        $this->getJson($this->homeUrl())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/notifications/'.$row->id)->assertOk()->assertJsonPath('data.is_read', false);
    }

    private function homeUrl(int $perPage = 30): string
    {
        return '/api/v1/notifications?surface=home&pagination_mode=cursor&per_page='.$perPage;
    }

    private function student(): User
    {
        return User::query()->forceCreate([
            'name_ar' => 'الطالب', 'role' => 'client', 'active' => true, 'marketing_notifications_enabled' => true,
        ]);
    }

    private function course(): Course
    {
        $course = Course::factory()->create([
            ...$this->legacyTenant('courses'),
            'name_ar' => 'عنوان الكورس الحالي', 'name_en' => 'Current course title',
            'image' => 'https://cdn.example/current-cover.jpg', 'is_coming_soon' => false,
            'is_catalog_visible' => true,
        ]);
        $module = CourseModule::query()->forceCreate(['course_id' => $course->id, 'title_ar' => 'الوحدة', 'order' => 1]);
        $project = Project::factory()->create();
        CourseSection::query()->forceCreate([
            'course_id' => $course->id, 'module_id' => $module->id, 'section_type' => 'project',
            'sectionable_type' => Project::class, 'sectionable_id' => $project->id, 'order' => 1,
        ]);
        CourseAccessPlan::query()->forceCreate([
            'course_id' => $course->id, 'code' => 'basic', 'name_ar' => 'Basic',
            'price_coins' => 300, 'is_active' => true, 'certificate_enabled' => false,
        ]);

        return $course;
    }

    /** SQLite retains legacy columns that the production MySQL migration drops. */
    private function legacyTenant(string $table): array
    {
        return Schema::hasColumn($table, 'tenant_id') ? ['tenant_id' => 1] : [];
    }

    private function notification(User $student, ?Course $course, string $type = 'new_course', array $overrides = []): StudentNotification
    {
        return StudentNotification::query()->create([
            'user_id' => $student->id, 'notification_type' => $type,
            'notifiable_type' => $course ? Course::class : null, 'notifiable_id' => $course?->id,
            'title_ar' => 'عنوان الحملة', 'title_en' => 'Campaign title',
            'message_ar' => 'تفاصيل الحملة', 'message_en' => 'Campaign body',
            'image_url' => 'https://cdn.example/campaign-art.jpg',
            'link' => $course ? 'rokn://course/'.$course->id : 'rokn://home',
            'is_read' => false, ...$overrides,
        ]);
    }
}
