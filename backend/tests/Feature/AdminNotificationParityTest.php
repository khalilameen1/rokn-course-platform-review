<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\NotificationsController;
use App\Jobs\SendStudentNotification;
use App\Models\NotificationCampaign;
use App\Services\NotificationCampaignService;
use App\Services\StudentNotificationService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\API\ApiTestCase;

final class AdminNotificationParityTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('notification_campaigns');
        Schema::create('notification_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_key', 64)->unique();
            $table->string('notification_type', 64)->index();
            $table->string('audience', 32)->default('all');
            $table->unsignedBigInteger('course_id')->nullable()->index();
            $table->string('notifiable_type')->nullable();
            $table->unsignedBigInteger('notifiable_id')->nullable();
            $table->json('user_ids')->nullable();
            $table->json('exclude_user_ids')->nullable();
            $table->unsignedBigInteger('authored_by')->nullable()->index();
            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->text('message_ar');
            $table->text('message_en')->nullable();
            $table->string('action_label_ar', 80)->nullable();
            $table->string('action_label_en', 80)->nullable();
            $table->text('link')->nullable();
            $table->text('image_url')->nullable();
            $table->string('status', 24)->default('queued')->index();
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
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('notification_campaigns');
        parent::tearDown();
    }

    public function test_queue_outage_keeps_the_durable_inbox_notification(): void
    {
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('queue unavailable'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $notification = DB::transaction(fn () => StudentNotificationService::notifyUser(
            $this->user,
            'course_enrolled',
            'الكورس جاهز',
            'Course ready',
            'ابدأ الآن',
            'Start now',
            null,
            null,
            null,
            'queue-outage-durable-inbox'
        ));

        self::assertNotNull($notification);
        $this->assertDatabaseHas('student_notifications', [
            'id' => $notification->id,
            'user_id' => $this->user->id,
            'delivery_key' => 'queue-outage-durable-inbox',
            'push_attempted_at' => null,
        ]);
    }

    public function test_direct_notification_uses_the_campaign_engine_with_copy_image_and_cta(): void
    {
        Queue::fake([SendStudentNotification::class]);
        $this->user->forceFill([
            'role' => 'client',
            'notifications_status' => true,
        ]);
        $this->user->deviceTokens()->create([
            'device_token' => 'test-device-token',
            'device_type' => 'android',
            'device_os' => 'android',
        ]);

        $request = Request::create('/admin/notifications', 'POST', [
            'user_id' => $this->user->id,
            'title_ar' => 'عنوان مهم',
            'message_ar' => 'رسالة الإشعار نفسها',
            'audience' => 'all',
            'action_label' => 'افتح المحفظة',
            'action_link' => '/wallet',
            'authoring_request_id' => (string) Str::uuid(),
        ]);
        $request->setUserResolver(fn () => $this->user);

        $response = app(NotificationsController::class)->store($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertTrue(session()->has('success'));
        $this->assertDatabaseHas('notification_campaigns', [
            'notification_type' => 'admin_message',
            'title_ar' => 'عنوان مهم',
            'title_en' => 'عنوان مهم',
            'message_ar' => 'رسالة الإشعار نفسها',
            'message_en' => 'رسالة الإشعار نفسها',
            'action_label_ar' => 'افتح المحفظة',
            'link' => 'rokn://wallet',
        ]);
        Queue::assertPushed(SendStudentNotification::class, function ($job): bool {
            return $job->uniqueId() !== '';
        });
    }

    public function test_broadcast_preserves_distinct_arabic_and_english_copy(): void
    {
        Queue::fake([SendStudentNotification::class]);

        $request = Request::create('/admin/notifications', 'POST', [
            'title_ar' => 'عنوان عربي',
            'message_ar' => 'رسالة عربية',
            'title_en' => 'English title',
            'message_en' => 'English message',
            'audience' => 'all',
            'authoring_request_id' => (string) Str::uuid(),
        ]);
        $request->setUserResolver(fn () => $this->user);

        app(NotificationsController::class)->store($request);

        $this->assertDatabaseHas('notification_campaigns', [
            'title_ar' => 'عنوان عربي',
            'message_ar' => 'رسالة عربية',
            'title_en' => 'English title',
            'message_en' => 'English message',
        ]);
        $campaign = NotificationCampaign::query()->latest('id')->firstOrFail();
        self::assertContains($campaign->status, [
            NotificationCampaign::STATUS_QUEUED,
            NotificationCampaign::STATUS_SCHEDULED,
        ]);
    }

    public function test_removed_legacy_form_aliases_are_rejected(): void
    {
        Queue::fake([SendStudentNotification::class]);

        $request = Request::create('/admin/notifications', 'POST', [
            'title' => 'عنوان قديم',
            'message' => 'رسالة قديمة',
            'audience' => 'all',
            'authoring_request_id' => (string) Str::uuid(),
        ]);
        $request->setUserResolver(fn () => $this->user);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(NotificationsController::class)->store($request);
    }

    public function test_failed_campaign_can_be_retried_once_without_changing_its_delivery_key(): void
    {
        Queue::fake([SendStudentNotification::class]);
        $campaign = NotificationCampaign::query()->create([
            'delivery_key' => 'failed-campaign-1',
            'notification_type' => 'admin_broadcast',
            'audience' => SendStudentNotification::AUDIENCE_ALL,
            'user_ids' => [],
            'exclude_user_ids' => [],
            'title_ar' => 'عنوان',
            'title_en' => 'Title',
            'message_ar' => 'رسالة',
            'message_en' => 'Message',
            'status' => NotificationCampaign::STATUS_FAILED,
            'retry_count' => 3,
            'failed_at' => now(),
            'failure_code' => 'recovery_exhausted',
            'queued_at' => now()->subHour(),
        ]);

        $service = app(NotificationCampaignService::class);
        self::assertTrue($service->retry($campaign));
        self::assertFalse($service->retry($campaign));

        $campaign->refresh();
        self::assertSame(NotificationCampaign::STATUS_QUEUED, $campaign->status);
        self::assertSame(0, $campaign->retry_count);
        self::assertNull($campaign->failed_at);
        self::assertNull($campaign->failure_code);
        Queue::assertPushed(SendStudentNotification::class, function ($job): bool {
            return $job->uniqueId() === 'failed-campaign-1';
        });
    }
}
