<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendUserPushNotification;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Project;
use App\Models\StudentNotification;
use App\Models\User;
use App\Support\LearningReminderSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class LearningReminderPreferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function learner(string $email, ?int $hour = null, ?string $zone = null): User
    {
        $user = User::query()->forceCreate([
            'name' => 'Reminder Student', 'email' => $email, 'role' => 'client',
            'active' => true, 'notifications_status' => true,
            ...($hour === null ? [] : [
                'learning_reminder_hour' => $hour, 'learning_reminder_timezone' => $zone,
            ]),
        ]);
        $course = Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'كورس التذكير', 'name_en' => 'Reminder course',
            'is_catalog_visible' => true, 'is_coming_soon' => false,
            'authoring_version' => 1, 'last_published_authoring_version' => 1,
        ]);
        $project = Project::query()->forceCreate(['requirements_text_ar' => 'مشروع تجريبي']);
        $module = CourseModule::query()->forceCreate([
            'course_id' => $course->id, 'title_ar' => 'الوحدة الأولى', 'order' => 1,
        ]);
        CourseSection::query()->forceCreate([
            'course_id' => $course->id, 'module_id' => $module->id,
            'sectionable_type' => Project::class, 'sectionable_id' => $project->id,
            'section_type' => 'project', 'order' => 1,
        ]);
        CourseEnrollment::query()->forceCreate([
            'tenant_id' => 1, 'user_id' => $user->id, 'course_id' => $course->id,
            'is_active' => true, 'enrolled_at' => now()->subDays(2),
            'access_granted_at' => now()->subDays(2),
        ]);
        return $user;
    }

    public function test_local_due_time_is_filtered_before_limit_and_not_sent_again_each_minute(): void
    {
        Queue::fake([SendUserPushNotification::class]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 04:35:00', 'UTC'));
        $notDue = $this->learner('not-due@rokn.test', 20, 'Africa/Cairo');
        // Kolkata is 10:05, not a UTC-hour or Cairo-hour match.
        $due = $this->learner('due@rokn.test', 10, 'Asia/Kolkata');
        $this->artisan('learning:send-nudges --limit=1')->assertSuccessful();
        $this->assertDatabaseMissing('student_notifications', ['user_id' => $notDue->id]);
        $this->assertDatabaseHas('student_notifications', [
            'user_id' => $due->id, 'notification_type' => 'learning_nudge',
        ]);
        $this->artisan('learning:send-nudges --limit=1')->assertSuccessful();
        self::assertSame(1, StudentNotification::query()->where('user_id', $due->id)->count());
    }

    public function test_an_existing_v60_account_keeps_the_cairo_twenty_hour_default(): void
    {
        Queue::fake([SendUserPushNotification::class]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 16:59:00', 'UTC'));
        $student = $this->learner('legacy-default@rokn.test');
        $student->refresh();
        self::assertSame(20, $student->learning_reminder_hour);
        self::assertSame('Africa/Cairo', $student->learning_reminder_timezone);
        $this->artisan('learning:send-nudges')->assertSuccessful();
        $this->assertDatabaseMissing('student_notifications', ['user_id' => $student->id]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 17:00:00', 'UTC'));
        $this->artisan('learning:send-nudges')->assertSuccessful();
        $this->assertDatabaseHas('student_notifications', ['user_id' => $student->id]);
    }

    public function test_carbon_schedule_uses_named_timezone_dst_not_a_fixed_offset(): void
    {
        self::assertSame(10, LearningReminderSchedule::dueHour('Europe/Berlin',
            CarbonImmutable::parse('2026-10-24 08:10:00', 'UTC')));
        self::assertSame(10, LearningReminderSchedule::dueHour('Europe/Berlin',
            CarbonImmutable::parse('2026-10-26 09:10:00', 'UTC')));
        self::assertNull(LearningReminderSchedule::dueHour('Europe/Berlin',
            CarbonImmutable::parse('2026-10-26 08:10:00', 'UTC')));
    }
}
