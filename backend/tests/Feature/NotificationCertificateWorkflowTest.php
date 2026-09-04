<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\NotificationsController;
use App\Jobs\DeliverStudentNotificationChunk;
use App\Jobs\RecoverPendingCertificate;
use App\Jobs\SendStudentNotification;
use App\Jobs\SendUserPushNotification;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\FinancialEntitlementHold;
use App\Models\NotificationCampaign;
use App\Models\NotificationCampaignRecipient;
use App\Models\StudentNotification;
use App\Models\User;
use App\Services\CertificateService;
use App\Services\CertificateEligibilityService;
use App\Services\CoursePublishingService;
use App\Services\NotificationService;
use App\Services\NotificationCampaignService;
use App\Services\PublicPortfolioService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Messaging;
use Mockery;
use Tests\TestCase;

final class NotificationCertificateWorkflowTest extends TestCase
{
    /** @var list<string> */
    private array $tables = [
        'classification_course', 'course_teacher',
        'photos',
        'account_file_deletions',
        'notification_push_deliveries', 'notification_campaign_recipients', 'admin_notifications',
        'notification_campaigns', 'user_device_tokens', 'student_notifications', 'user_project_evaluations',
        'portfolio_media', 'portfolio_items', 'user_level', 'levels',
        'course_authoring_revision_entities', 'course_authoring_revisions', 'financial_entitlement_holds',
        'project_submission_review_decisions', 'project_submissions', 'projects', 'student_section_progress', 'course_sections', 'certificates', 'course_enrollments',
        'courses', 'users',
    ];

    private ?string $certificateDiskRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        Log::spy();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->certificateDiskRoot) {
            File::deleteDirectory($this->certificateDiskRoot);
        }
        foreach ($this->tables as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_large_broadcast_is_split_into_bounded_queue_jobs(): void
    {
        $now = now();
        $rows = [];
        for ($index = 1; $index <= 1001; $index++) {
            $rows[] = [
                'name' => 'Student ' . $index,
                'email' => 'student-' . $index . '@example.com',
                'role' => 'client',
                'active' => true,
                'notifications_status' => true,
                'marketing_notifications_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('users')->insert($chunk);
        }
        Queue::fake([DeliverStudentNotificationChunk::class]);
        $this->notificationCampaign('broadcast:test:1001', [
            'notification_type' => 'admin_broadcast',
        ]);

        $job = new SendStudentNotification('broadcast:test:1001');
        $job->handle();

        Queue::assertPushed(DeliverStudentNotificationChunk::class, 3);
        $queued = Queue::pushed(DeliverStudentNotificationChunk::class);
        foreach ($queued as $queuedJob) {
            $reflection = new \ReflectionProperty($queuedJob, 'userIds');
            $reflection->setAccessible(true);
            self::assertLessThanOrEqual(500, count($reflection->getValue($queuedJob)));
        }
    }

    public function test_course_notification_service_queues_a_selector_instead_of_enrollment_ids(): void
    {
        $this->allowPublishedCourseNotification();
        $course = $this->course();
        $student = $this->user('service-selector@example.com');
        DB::table('course_enrollments')->insert([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Queue::fake([SendStudentNotification::class]);

        NotificationService::notifyCourseUpdate($course);

        $campaign = NotificationCampaign::query()
            ->where('course_id', $course->id)
            ->where('notification_type', 'course_update')
            ->firstOrFail();
        self::assertSame([], $campaign->user_ids);
        self::assertSame((int) $course->id, (int) $campaign->course_id);
        self::assertSame(SendStudentNotification::AUDIENCE_ENROLLED, $campaign->audience);

        $queued = collect(Queue::pushed(SendStudentNotification::class))
            ->contains(fn (SendStudentNotification $job): bool =>
                $this->jobProperty($job, 'deliveryKey') === $campaign->delivery_key
            );
        $campaign->refresh();
        self::assertTrue(
            $queued || $campaign->status === NotificationCampaign::STATUS_SCHEDULED,
            'Course notification must be queued now or durably scheduled after quiet hours.'
        );
        if (!$queued) {
            self::assertNotNull($campaign->scheduled_at);
        }
    }

    public function test_admin_course_broadcast_queues_audience_selector_without_materializing_ids(): void
    {
        $this->allowPublishedCourseNotification();
        $course = $this->course();
        $student = $this->user('admin-selector@example.com');
        DB::table('course_enrollments')->insert([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Queue::fake([SendStudentNotification::class]);
        $admin = $this->user('notification-admin@example.com');
        $admin->forceFill(['role' => 'admin'])->save();
        $request = Request::create('/admin/notifications', 'POST', [
            'title_ar' => 'جرّب الكورس',
            'message_ar' => 'ابدأ أول خطوة الآن',
            'course_id' => $course->id,
            'audience' => 'not_enrolled',
            'authoring_request_id' => (string) Str::uuid(),
        ]);
        $request->setUserResolver(static fn () => $admin);

        app(NotificationsController::class)->store($request);

        $campaign = NotificationCampaign::query()
            ->where('course_id', $course->id)
            ->where('audience', SendStudentNotification::AUDIENCE_NOT_ENROLLED)
            ->firstOrFail();
        self::assertSame([], $campaign->user_ids);
        self::assertSame([], $campaign->exclude_user_ids);

        $queued = collect(Queue::pushed(SendStudentNotification::class))
            ->contains(fn (SendStudentNotification $job): bool =>
                $this->jobProperty($job, 'deliveryKey') === $campaign->delivery_key
            );
        $campaign->refresh();
        self::assertTrue(
            $queued || $campaign->status === NotificationCampaign::STATUS_SCHEDULED,
            'Admin notification must be queued now or durably scheduled after quiet hours.'
        );
        if (!$queued) {
            self::assertNotNull($campaign->scheduled_at);
        }
    }

    public function test_worker_resolves_course_audiences_with_chunked_queries(): void
    {
        $course = $this->course();
        $enrolled = $this->user('enrolled-selector@example.com');
        $notEnrolled = $this->user('not-enrolled-selector@example.com');
        DB::table('course_enrollments')->insert([
            'user_id' => $enrolled->id,
            'course_id' => $course->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Queue::fake([DeliverStudentNotificationChunk::class]);
        $this->notificationCampaign('course-selector:enrolled', [
            'notification_type' => 'course_update',
            'audience' => SendStudentNotification::AUDIENCE_ENROLLED,
            'course_id' => $course->id,
            'notifiable_type' => Course::class,
            'notifiable_id' => $course->id,
        ]);
        $enrolledJob = new SendStudentNotification('course-selector:enrolled');
        $enrolledJob->handle();

        Queue::assertPushed(DeliverStudentNotificationChunk::class, function (DeliverStudentNotificationChunk $job) use ($enrolled): bool {
            return $this->jobProperty($job, 'userIds') === [(int) $enrolled->id];
        });

        Queue::fake([DeliverStudentNotificationChunk::class]);
        $this->notificationCampaign('course-selector:not-enrolled', [
            'notification_type' => 'course_promotion',
            'audience' => SendStudentNotification::AUDIENCE_NOT_ENROLLED,
            'course_id' => $course->id,
            'notifiable_type' => Course::class,
            'notifiable_id' => $course->id,
        ]);
        $notEnrolledJob = new SendStudentNotification('course-selector:not-enrolled');
        $notEnrolledJob->handle();

        Queue::assertPushed(DeliverStudentNotificationChunk::class, function (DeliverStudentNotificationChunk $job) use ($notEnrolled): bool {
            return $this->jobProperty($job, 'userIds') === [(int) $notEnrolled->id];
        });
    }

    public function test_course_campaign_excludes_an_account_level_financial_hold(): void
    {
        $course = $this->course();
        $student = $this->user('course-hold-selector@example.com');
        DB::table('financial_entitlement_holds')->insert([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'enrollment_id' => null,
            'status' => FinancialEntitlementHold::STATUS_ACTIVE,
            'entitlement_scope' => 'course',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Queue::fake([DeliverStudentNotificationChunk::class]);
        $this->notificationCampaign('course-selector:financial-hold', [
            'notification_type' => 'course_promotion',
            'audience' => SendStudentNotification::AUDIENCE_NOT_ENROLLED,
            'course_id' => $course->id,
            'notifiable_type' => Course::class,
            'notifiable_id' => $course->id,
        ]);

        (new SendStudentNotification('course-selector:financial-hold'))->handle();

        Queue::assertNotPushed(DeliverStudentNotificationChunk::class);
        self::assertSame(
            NotificationCampaign::STATUS_COMPLETED,
            NotificationCampaign::query()
                ->where('delivery_key', 'course-selector:financial-hold')
                ->value('status')
        );
    }

    public function test_explicit_audience_is_bounded_and_failure_logs_no_user_ids(): void
    {
        $this->notificationCampaign('account-notice:test', [
            'notification_type' => 'account_notice',
            'user_ids' => [37, 42],
        ]);
        $job = new SendStudentNotification('account-notice:test');

        $job->failed(new \RuntimeException('test failure'));

        Log::shouldHaveReceived('error')->with(
            'SendStudentNotification job failed',
            Mockery::on(static function (array $context): bool {
                return ($context['delivery_key'] ?? null) === 'account-notice:test'
                    && !array_key_exists('user_ids', $context)
                    && !array_key_exists('explicit_user_ids_count', $context);
            })
        )->once();

        $this->expectException(\InvalidArgumentException::class);
        app(NotificationCampaignService::class)->queue(
            'account_notice',
            range(1, SendStudentNotification::MAX_EXPLICIT_USER_IDS + 1),
            null,
            null,
            'تنبيه',
            'Notice',
            'راجع حسابك',
            'Review your account',
            null,
            [],
            'account-notice:too-large',
            null,
            SendStudentNotification::AUDIENCE_ALL
        );
    }

    public function test_chunk_retry_creates_one_inbox_row_per_user_and_delivery_key(): void
    {
        $first = $this->user('first@example.com');
        $second = $this->user('second@example.com');
        Queue::fake([SendUserPushNotification::class]);
        $campaign = $this->notificationCampaign('broadcast:retry-safe', [
            'notification_type' => 'admin_broadcast',
            'status' => NotificationCampaign::STATUS_DELIVERING,
            'recipients_count' => 2,
            'selection_finished_at' => now(),
        ]);
        foreach ([$first, $second] as $student) {
            NotificationCampaignRecipient::query()->create([
                'notification_campaign_id' => $campaign->id,
                'user_id' => $student->id,
                'status' => NotificationCampaignRecipient::STATUS_PENDING,
            ]);
        }

        $job = new DeliverStudentNotificationChunk(
            [$first->id, $second->id],
            'broadcast:retry-safe'
        );
        $job->handle();
        $job->handle();

        self::assertSame(2, StudentNotification::query()->count());
        self::assertSame(2, StudentNotification::query()
            ->where('delivery_key', 'broadcast:retry-safe')
            ->distinct('user_id')
            ->count('user_id'));
    }

    public function test_chunk_rechecks_course_audience_after_selection(): void
    {
        $course = $this->course();
        $student = $this->user('joined-after-campaign-selection@example.com');
        Queue::fake([SendUserPushNotification::class]);
        $campaign = $this->notificationCampaign('course-promotion:audience-drift', [
            'notification_type' => 'course_promotion',
            'audience' => SendStudentNotification::AUDIENCE_NOT_ENROLLED,
            'course_id' => $course->id,
            'notifiable_type' => Course::class,
            'notifiable_id' => $course->id,
            'status' => NotificationCampaign::STATUS_DELIVERING,
            'recipients_count' => 1,
            'selection_finished_at' => now(),
        ]);
        NotificationCampaignRecipient::query()->create([
            'notification_campaign_id' => $campaign->id,
            'user_id' => $student->id,
            'status' => NotificationCampaignRecipient::STATUS_PENDING,
        ]);

        DB::table('course_enrollments')->insert([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new DeliverStudentNotificationChunk(
            [$student->id],
            'course-promotion:audience-drift'
        ))->handle();

        $this->assertDatabaseMissing('student_notifications', [
            'user_id' => $student->id,
            'delivery_key' => 'course-promotion:audience-drift',
        ]);
        $this->assertDatabaseHas('notification_campaign_recipients', [
            'notification_campaign_id' => $campaign->id,
            'user_id' => $student->id,
            'status' => NotificationCampaignRecipient::STATUS_SKIPPED,
            'resolution_code' => 'preference_or_course_changed',
        ]);
    }

    public function test_push_job_claim_is_at_most_once_across_retries(): void
    {
        $user = $this->user('push@example.com');
        DB::table('user_device_tokens')->insert([
            'user_id' => $user->id,
            'device_token' => 'push-once-token',
            'device_type' => 'android',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('send')->once()->andReturn(['name' => 'push-once']);
        $this->app->instance(Messaging::class, $messaging);
        $notification = StudentNotification::query()->create([
            'user_id' => $user->id,
            'delivery_key' => 'push:once',
            'notification_type' => 'admin_message',
            'title_ar' => 'عنوان',
            'title_en' => 'Title',
            'message_ar' => 'رسالة',
            'message_en' => 'Message',
            'is_read' => false,
        ]);

        Carbon::setTestNow('2026-08-07 10:00:00');
        (new SendUserPushNotification($notification->id))->handle(
            app(\App\Services\StudentNotificationPresentationService::class)
        );
        $firstAttempt = $notification->fresh()->push_attempted_at;
        Carbon::setTestNow('2026-08-07 11:00:00');
        (new SendUserPushNotification($notification->id))->handle(
            app(\App\Services\StudentNotificationPresentationService::class)
        );

        self::assertNotNull($firstAttempt);
        self::assertTrue($firstAttempt->equalTo($notification->fresh()->push_attempted_at));
    }

    public function test_stalled_push_claim_is_quarantined_without_duplicate_delivery(): void
    {
        Queue::fake([SendUserPushNotification::class]);
        Carbon::setTestNow('2026-08-31 12:00:00');
        $user = $this->user('stalled-push@example.com');
        $user->forceFill(['notifications_status' => true, 'active' => true])->save();
        DB::table('user_device_tokens')->insert([
            'user_id' => $user->id,
            'device_token' => 'stalled-push-token',
            'device_type' => 'android',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $notification = StudentNotification::query()->create([
            'user_id' => $user->id,
            'delivery_key' => 'push:stalled',
            'notification_type' => 'service_notice',
            'title_ar' => 'عنوان',
            'title_en' => 'Title',
            'message_ar' => 'رسالة',
            'message_en' => 'Message',
            'is_read' => false,
            'push_attempted_at' => now()->subMinutes(20),
        ]);

        $this->artisan('notifications:retry-stalled')->assertExitCode(0);

        $notification->refresh();
        self::assertNotNull($notification->push_attempted_at);
        self::assertNotNull($notification->push_failed_at);
        self::assertSame('delivery_unknown_after_worker_loss', $notification->push_failure_code);
        Queue::assertNotPushed(SendUserPushNotification::class);
    }

    public function test_new_course_revision_cannot_revoke_earned_certificate_eligibility(): void
    {
        $user = $this->user('grandfathered-graduate@example.com');
        $course = $this->course();
        $course->forceFill([
            'authoring_version' => 4,
            'last_published_authoring_version' => 4,
        ])->save();
        DB::table('course_enrollments')->insert([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'is_active' => true,
            'completed_curriculum_revision' => 4,
            'curriculum_completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Revision 5 is being authored and contains a new unfinished section.
        // The earned revision 4 remains the certificate contract.
        $course->forceFill([
            'is_coming_soon' => true,
            'authoring_version' => 5,
            'last_published_authoring_version' => 4,
        ])->save();
        DB::table('course_sections')->insert([
            'course_id' => $course->id,
            'module_id' => DB::table('course_modules')
                ->where('course_id', $course->id)
                ->value('id'),
            'section_type' => 'lesson',
            'order' => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eligibility = app(CertificateEligibilityService::class)->for($user, $course->fresh());

        self::assertTrue($eligibility['included']);
        self::assertTrue($eligibility['available']);
        self::assertSame('ready', $eligibility['reason']);
    }

    public function test_pending_certificate_is_recovered_to_configured_shared_disk(): void
    {
        $this->certificateDiskRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rokn-certificate-' . uniqid('', true);
        File::ensureDirectoryExists($this->certificateDiskRoot);
        config()->set('filesystems.disks.certificate-test', [
            'driver' => 'local',
            'root' => $this->certificateDiskRoot,
            'url' => 'https://cdn.example.test/certificates',
            'visibility' => 'public',
        ]);
        Storage::forgetDisk('certificate-test');
        config()->set('certificate.disk', 'certificate-test');
        config()->set('certificate.font_regular', resource_path('fonts/Cairo.ttf'));

        $user = $this->user('certificate@example.com');
        $course = $this->course();
        $course->forceFill(['certificate_text_template_key' => 'projects'])->save();
        DB::table('course_enrollments')->insert([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pending = Certificate::query()->create([
            'public_id' => '11111111-2222-4333-8444-555555555555',
            'user_id' => $user->id,
            'course_id' => $course->id,
            'holder_name' => 'طالب ركن',
            'course_name' => (string) $course->name_ar,
            'certificate_text_template_key' => 'projects',
            'certificate_text' => 'تقديرًا لإنجاز مشروعات كورس',
            'verification_level' => 'completion',
            'image_path' => 'pending',
            'generated_at' => now(),
            'status' => 'active',
        ]);

        $certificate = app(CertificateService::class)->generate($user, $course);

        self::assertNotNull($certificate);
        self::assertSame($pending->id, $certificate->id);
        self::assertSame('projects', $certificate->certificate_text_template_key);
        self::assertSame(
            'تقديرًا لإنجاز مشروعات كورس',
            $certificate->certificate_text
        );
        self::assertNotSame('pending', $certificate->image_path);
        Storage::disk('certificate-test')->assertExists($certificate->image_path);

        $verification = app(PublicPortfolioService::class)->findCredential(
            (string) $certificate->public_id
        );
        self::assertNotNull($verification);
        self::assertTrue($verification['is_limited_certificate_view']);
        self::assertSame(
            $certificate->public_id,
            $verification['highlighted_certificate']['public_id']
        );
        self::assertSame('active', $verification['highlighted_certificate']['status']);

        $firstArtifact = Storage::disk('certificate-test')->get($certificate->image_path);
        Storage::disk('certificate-test')->delete($certificate->image_path);
        // Recovery belongs to the issued row. Even if a later deployment no
        // longer recognises the course's live selection, a complete immutable
        // snapshot must still rebuild the exact artifact.
        $course->forceFill(['certificate_text_template_key' => 'removed-template'])->save();
        config()->set(
            'certificate.text_templates.projects.text',
            'نص حي جديد لا يجوز أن يغير شهادة صادرة'
        );

        $recovered = app(CertificateService::class)->generate($user, $course);

        self::assertNotNull($recovered);
        self::assertSame('projects', $recovered->certificate_text_template_key);
        self::assertSame(
            'تقديرًا لإنجاز مشروعات كورس',
            $recovered->certificate_text
        );
        self::assertSame(
            hash('sha256', $firstArtifact),
            hash(
                'sha256',
                Storage::disk('certificate-test')->get($recovered->image_path)
            )
        );
    }

    public function test_new_certificate_snapshots_the_locked_course_revision_not_a_stale_model(): void
    {
        $this->certificateDiskRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rokn-certificate-' . uniqid('', true);
        File::ensureDirectoryExists($this->certificateDiskRoot);
        config()->set('filesystems.disks.certificate-test', [
            'driver' => 'local',
            'root' => $this->certificateDiskRoot,
            'url' => 'https://cdn.example.test/certificates',
            'visibility' => 'public',
        ]);
        Storage::forgetDisk('certificate-test');
        config()->set('certificate.disk', 'certificate-test');
        config()->set('certificate.font_regular', resource_path('fonts/Cairo.ttf'));

        $user = $this->user('certificate-revision@example.com');
        $course = $this->course();
        $projectId = DB::table('projects')->insertGetId([
            'is_graduation_project' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sectionId = (int) DB::table('course_sections')
            ->where('course_id', $course->id)
            ->value('id');
        DB::table('course_sections')->where('id', $sectionId)->update([
            'section_type' => 'project',
            'sectionable_type' => \App\Models\Project::class,
            'sectionable_id' => $projectId,
        ]);
        DB::table('course_enrollments')->insert([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('student_section_progress')->insert([
            'user_id' => $user->id,
            'course_section_id' => $sectionId,
            'is_completed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('project_submissions')->insert([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'project_id' => $projectId,
            'idempotency_key' => 'certificate-project-' . $projectId,
            'review_status' => \App\Models\ProjectSubmission::STATUS_PASSED,
            'submitted_at' => now(),
            'reviewed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // This model still carries the old selection while the committed
        // course revision has moved to the projects wording.
        self::assertSame('completion', $course->certificate_text_template_key);
        DB::table('courses')->where('id', $course->id)->update([
            'certificate_text_template_key' => 'projects',
            'updated_at' => now(),
        ]);

        $certificate = app(CertificateService::class)->generate(
            $user,
            $course,
            'طالب ركن'
        );

        self::assertNotNull($certificate);
        self::assertSame('projects', $certificate->certificate_text_template_key);
        self::assertSame(
            'تقديرًا لإنجاز مشروعات كورس',
            $certificate->certificate_text
        );
    }

    private function user(string $email): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Student',
            'email' => $email,
            'role' => 'client',
            'active' => true,
            'notifications_status' => true,
            'marketing_notifications_enabled' => true,
            'portfolio_slug' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    private function course(): Course
    {
        $id = DB::table('courses')->insertGetId([
            'name_ar' => 'كورس تجريبي',
            'name_en' => 'Test course',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $moduleId = DB::table('course_modules')->insertGetId([
            'course_id' => $id,
            'title_ar' => 'محتوى الكورس',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('course_sections')->insert([
            'course_id' => $id,
            'module_id' => $moduleId,
            'section_type' => 'lesson',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Course::query()->findOrFail($id);
    }

    private function jobProperty(object $job, string $name): mixed
    {
        $property = new \ReflectionProperty($job, $name);
        $property->setAccessible(true);

        return $property->getValue($job);
    }

    private function allowPublishedCourseNotification(): void
    {
        $publishing = Mockery::mock(CoursePublishingService::class);
        $publishing->shouldReceive('audit')->andReturn(['ready' => true, 'issues' => []]);
        $this->app->instance(CoursePublishingService::class, $publishing);
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role')->default('client');
            $table->boolean('active')->default(true);
            $table->boolean('notifications_status')->default(true);
            $table->boolean('marketing_notifications_enabled')->default(true);
            $table->string('portfolio_slug')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->boolean('awards_badge')->default(false);
            $table->string('certificate_text_template_key', 32)->default('completion');
            $table->boolean('is_coming_soon')->default(false);
            $table->boolean('is_catalog_visible')->default(true);
            $table->unsignedBigInteger('authoring_version')->default(1);
            $table->unsignedBigInteger('last_published_authoring_version')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('badge_track')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('course_teacher', function (Blueprint $table): void {
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('teacher_id');
            $table->timestamps();
        });
        Schema::create('classification_course', function (Blueprint $table): void {
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('classification_id');
        });
        Schema::create('photos', function (Blueprint $table): void {
            $table->id();
            $table->string('path');
            $table->string('type')->default('featured');
            $table->string('photoable_type');
            $table->unsignedBigInteger('photoable_id');
            $table->timestamps();
        });
        Schema::create('course_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('completed_curriculum_revision')->nullable();
            $table->timestamp('curriculum_completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('course_modules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->string('title_ar')->nullable();
            $table->unsignedInteger('order')->default(1);
            $table->timestamps();
        });
        Schema::create('financial_entitlement_holds', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->string('status', 24)->default('active');
            $table->string('entitlement_scope', 16)->default('course');
            $table->timestamps();
        });
        Schema::create('certificates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('holder_name')->nullable();
            $table->string('course_name')->nullable();
            $table->string('certificate_text_template_key', 32)->nullable();
            $table->string('certificate_text')->nullable();
            $table->string('image_path');
            $table->uuid('generation_lease_id')->nullable()->index();
            $table->timestamp('generated_at')->nullable();
            $table->string('status')->default('active');
            $table->string('verification_level', 24)->default('completion');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedTinyInteger('recovery_attempts')->default(0);
            $table->timestamp('recovery_next_attempt_at')->nullable();
            $table->timestamp('recovery_failed_at')->nullable();
            $table->string('recovery_failure_code', 64)->nullable();
            $table->timestamp('artifact_checked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'course_id']);
        });
        Schema::create('portfolio_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('source_project_id')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('slug')->nullable();
            $table->string('role')->nullable();
            $table->json('tools')->nullable();
            $table->string('external_url')->nullable();
            $table->date('completed_at')->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('portfolio_media', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('portfolio_item_id');
            $table->string('file_path');
            $table->string('file_type');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('levels', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->string('badge_image')->nullable();
            $table->unsignedInteger('order')->default(1);
            $table->timestamps();
        });
        Schema::create('user_level', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('level_id');
            $table->unsignedBigInteger('course_id');
            $table->timestamp('earned_at')->nullable();
            $table->timestamps();
        });
        Schema::create('course_sections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('module_id');
            $table->string('sectionable_type')->nullable();
            $table->unsignedBigInteger('sectionable_id')->nullable();
            $table->string('section_type')->nullable();
            $table->unsignedInteger('order')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('course_authoring_revisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('canonical_course_id');
            $table->unsignedBigInteger('revision_course_id');
            $table->unsignedBigInteger('base_authoring_version');
            $table->unsignedBigInteger('published_authoring_version')->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('active_slot', 80)->nullable()->unique();
            $table->uuid('clone_key')->unique();
            $table->timestamps();
        });
        Schema::create('course_authoring_revision_entities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_authoring_revision_id');
            $table->string('entity_type', 120);
            $table->unsignedBigInteger('source_entity_id');
            $table->unsignedBigInteger('revision_entity_id');
            $table->boolean('survives_publish')->default(false);
            $table->boolean('carries_learner_state')->default(false);
            $table->unsignedBigInteger('learner_root_entity_id')->nullable();
        });
        Schema::create('student_section_progress', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_section_id');
            $table->boolean('is_completed')->default(false);
            $table->timestamps();
        });
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_graduation_project')->default(false);
            $table->timestamps();
        });
        Schema::create('project_submissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('project_id');
            $table->string('idempotency_key', 100);
            $table->text('submission_text')->nullable();
            $table->string('submission_file')->nullable();
            $table->string('original_file_name')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->json('submission_metadata')->nullable();
            $table->string('effort_status', 30)->default('unknown');
            $table->string('review_status', 30)->default('pending');
            $table->string('review_source', 40)->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('auto_pass_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'project_id', 'idempotency_key']);
        });
        Schema::create('project_submission_review_decisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('decision_id')->unique();
            $table->unsignedBigInteger('submission_id');
            $table->unsignedInteger('sequence');
            $table->string('status', 30);
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('feedback')->nullable();
            $table->string('source', 40);
            $table->unsignedBigInteger('reviewer_id')->nullable();
            $table->string('reviewer_role', 24)->nullable();
            $table->timestamp('decided_at');
            $table->json('decision_metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('user_project_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('project_id');
            $table->boolean('passed')->default(false);
            $table->timestamps();
        });
        Schema::create('student_notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('delivery_key', 64)->nullable();
            $table->string('notification_type');
            $table->string('notifiable_type')->nullable();
            $table->unsignedBigInteger('notifiable_id')->nullable();
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('message_ar');
            $table->text('message_en');
            $table->string('link')->nullable();
            $table->string('image_url')->nullable();
            $table->string('action_label_ar')->nullable();
            $table->string('action_label_en')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('push_attempted_at')->nullable();
            $table->unsignedSmallInteger('push_attempts')->default(0);
            $table->timestamp('push_sent_at')->nullable();
            $table->timestamp('push_failed_at')->nullable();
            $table->string('push_failure_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'delivery_key']);
        });
        Schema::create('admin_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('system_key')->nullable()->unique();
            $table->string('surface')->default('announcement');
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('action_label_ar')->nullable();
            $table->string('action_label_en')->nullable();
            $table->string('secondary_action_label_ar')->nullable();
            $table->string('secondary_action_label_en')->nullable();
            $table->text('link')->nullable();
            $table->string('image')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_dismissible')->default(true);
            $table->unsignedInteger('priority')->default(0);
            $table->unsignedInteger('cooldown_hours')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->uuid('authoring_request_id')->nullable();
            $table->timestamps();
        });
        Schema::create('notification_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_key', 64)->unique();
            $table->string('notification_type', 64);
            $table->string('audience', 32)->default('all');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->string('notifiable_type')->nullable();
            $table->unsignedBigInteger('notifiable_id')->nullable();
            $table->json('user_ids')->nullable();
            $table->json('exclude_user_ids')->nullable();
            $table->unsignedBigInteger('authored_by')->nullable();
            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->text('message_ar');
            $table->text('message_en')->nullable();
            $table->string('action_label_ar', 80)->nullable();
            $table->string('action_label_en', 80)->nullable();
            $table->text('link')->nullable();
            $table->text('image_url')->nullable();
            $table->string('status', 24)->default('queued');
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('inbox_count')->default(0);
            $table->unsignedInteger('resolved_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->unsignedBigInteger('selection_cursor')->default(0);
            $table->timestamp('selection_finished_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('coordinator_finished_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestamps();
        });
        Schema::create('notification_campaign_recipients', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('notification_campaign_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status', 24)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('resolution_code', 64)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['notification_campaign_id', 'user_id']);
        });
        Schema::create('user_device_tokens', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('device_token')->unique();
            $table->string('device_type')->nullable();
            $table->timestamps();
        });
        Schema::create('notification_push_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_notification_id');
            $table->unsignedBigInteger('user_device_token_id');
            $table->string('token_fingerprint', 64);
            $table->string('device_os', 20)->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['student_notification_id', 'user_device_token_id']);
        });
        (require database_path('migrations/2026_08_07_000022_create_account_file_deletions_table.php'))->up();
    }

    public function test_recovery_releases_a_stale_generation_lease_before_retrying(): void
    {
        Storage::fake('certificate-recovery-test');
        config()->set('certificate.disk', 'certificate-recovery-test');
        config()->set('operations.certificate_recovery_stale_minutes', 5);

        $user = $this->user('stale-certificate-lease@example.com');
        $course = $this->course();
        $certificate = Certificate::query()->create([
            'public_id' => '21111111-2222-4333-8444-555555555555',
            'user_id' => $user->id,
            'course_id' => $course->id,
            'holder_name' => 'طالب ركن',
            'course_name' => (string) $course->name_ar,
            'certificate_text_template_key' => 'completion',
            'certificate_text' => 'تقديرًا لإتمام متطلبات كورس',
            'image_path' => 'pending',
            'generation_lease_id' => '31111111-2222-4333-8444-555555555555',
            'generated_at' => now()->subHour(),
            'status' => 'active',
        ]);
        Certificate::query()->whereKey($certificate->id)->update([
            'updated_at' => now()->subMinutes(10),
        ]);

        $service = Mockery::mock(CertificateService::class);
        $service->shouldReceive('generate')
            ->once()
            ->withArgs(function (User $resolvedUser, Course $resolvedCourse) use (
                $user,
                $course,
                $certificate
            ): bool {
                self::assertSame($user->id, $resolvedUser->id);
                self::assertSame($course->id, $resolvedCourse->id);

                $pending = Certificate::query()->findOrFail($certificate->id);
                self::assertNull($pending->generation_lease_id);
                Storage::disk('certificate-recovery-test')->put(
                    'certificates/recovered.png',
                    'certificate-bytes'
                );
                $pending->forceFill([
                    'image_path' => 'certificates/recovered.png',
                ])->save();

                return true;
            })
            ->andReturnUsing(fn (): Certificate => Certificate::query()
                ->findOrFail($certificate->id));

        (new RecoverPendingCertificate((int) $certificate->id))->handle($service);

        $certificate->refresh();
        self::assertSame('certificates/recovered.png', $certificate->image_path);
        self::assertSame(1, $certificate->recovery_attempts);
    }

    public function test_recovery_does_not_steal_a_live_generation_lease(): void
    {
        config()->set('operations.certificate_recovery_stale_minutes', 5);

        $user = $this->user('live-certificate-lease@example.com');
        $course = $this->course();
        $certificate = Certificate::query()->create([
            'public_id' => '41111111-2222-4333-8444-555555555555',
            'user_id' => $user->id,
            'course_id' => $course->id,
            'holder_name' => 'طالب ركن',
            'course_name' => (string) $course->name_ar,
            'certificate_text_template_key' => 'completion',
            'certificate_text' => 'تقديرًا لإتمام متطلبات كورس',
            'image_path' => 'pending',
            'generation_lease_id' => '51111111-2222-4333-8444-555555555555',
            'generated_at' => now()->subHour(),
            'status' => 'active',
        ]);
        $leaseUpdatedAt = $certificate->updated_at?->format('Y-m-d H:i:s.u');

        $service = Mockery::mock(CertificateService::class);
        $service->shouldNotReceive('generate');

        (new RecoverPendingCertificate((int) $certificate->id))->handle($service);

        $certificate->refresh();
        self::assertSame(
            '51111111-2222-4333-8444-555555555555',
            $certificate->generation_lease_id
        );
        self::assertSame(0, $certificate->recovery_attempts);
        self::assertSame(
            $leaseUpdatedAt,
            $certificate->updated_at?->format('Y-m-d H:i:s.u')
        );
    }

    /** @param array<string,mixed> $overrides */
    private function notificationCampaign(string $deliveryKey, array $overrides = []): NotificationCampaign
    {
        return NotificationCampaign::query()->create($overrides + [
            'delivery_key' => $deliveryKey,
            'notification_type' => 'admin_broadcast',
            'audience' => SendStudentNotification::AUDIENCE_ALL,
            'user_ids' => [],
            'exclude_user_ids' => [],
            'title_ar' => 'عنوان',
            'title_en' => 'Title',
            'message_ar' => 'رسالة',
            'message_en' => 'Message',
            'link' => 'rokn://home',
            'action_label_ar' => 'افتح ركن',
            'action_label_en' => 'Open Rokn',
            'status' => NotificationCampaign::STATUS_QUEUED,
        ]);
    }
}
