<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendUserPushNotification;
use App\Models\StudentNotification;
use App\Models\User;
use App\Services\StudentNotificationPresentationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Mockery;
use Tests\TestCase;

final class NotificationDeliveryHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('role')->default('client');
            $table->boolean('active')->default(true);
            $table->boolean('notifications_status')->default(true);
            $table->boolean('marketing_notifications_enabled')->default(true);
            $table->string('preferred_locale', 5)->default('ar');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('user_device_tokens', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('device_token')->unique();
            $table->string('device_type')->nullable();
            $table->string('device_os')->nullable();
            $table->string('device_id')->nullable();
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::dropIfExists('notification_push_deliveries');
        Schema::dropIfExists('student_notifications');
        Schema::dropIfExists('user_device_tokens');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_legacy_claim_is_quarantined_after_per_device_ledger_migration(): void
    {
        Queue::fake([SendUserPushNotification::class]);
        Carbon::setTestNow('2026-09-02 12:00:00');
        $user = $this->user('legacy-claimed-push@example.com');
        $notification = $this->notification($user, 'push:legacy-claimed', [
            'push_attempted_at' => now()->subMinutes(20),
        ]);

        $this->artisan('notifications:retry-stalled')->assertExitCode(0);

        $notification->refresh();
        self::assertNull($notification->push_sent_at);
        self::assertNotNull($notification->push_failed_at);
        self::assertSame('delivery_unknown_after_worker_loss', $notification->push_failure_code);
        Queue::assertNotPushed(SendUserPushNotification::class);
    }

    public function test_unregistered_response_does_not_delete_a_token_rotated_in_flight(): void
    {
        $user = $this->user('rotated-push@example.com');
        $tokenId = $this->token($user, 'stale-device-token');
        $notification = $this->notification($user, 'push:token-rotated-in-flight');
        $this->fakeUnregisteredResponseAfterRotation(
            $tokenId,
            'fresh-device-token'
        );

        (new SendUserPushNotification($notification->id))->handle(
            app(StudentNotificationPresentationService::class)
        );

        $this->assertDatabaseHas('user_device_tokens', [
            'id' => $tokenId,
            'user_id' => $user->id,
            'device_token' => 'fresh-device-token',
        ]);
        $this->assertDatabaseHas('notification_push_deliveries', [
            'student_notification_id' => $notification->id,
            'status' => 'failed',
            'failure_code' => 'token_unregistered',
        ]);
        self::assertNull($notification->fresh()->push_sent_at);
        self::assertNotNull($notification->fresh()->push_failed_at);
    }

    private function user(string $email): User
    {
        $user = User::query()->create([
            'name' => 'Student',
            'email' => $email,
            'role' => 'client',
            'active' => true,
            'notifications_status' => true,
            'marketing_notifications_enabled' => true,
            'preferred_locale' => 'ar',
        ]);

        return $user->refresh();
    }

    /** @param array<string,mixed> $overrides */
    private function notification(User $user, string $key, array $overrides = []): StudentNotification
    {
        return StudentNotification::query()->create(array_merge([
            'user_id' => $user->id,
            'delivery_key' => $key,
            'notification_type' => 'service_notice',
            'title_ar' => 'عنوان',
            'title_en' => 'Title',
            'message_ar' => 'رسالة',
            'message_en' => 'Message',
            'is_read' => false,
        ], $overrides));
    }

    private function token(User $user, string $value): int
    {
        return (int) DB::table('user_device_tokens')->insertGetId([
            'user_id' => $user->id,
            'device_token' => $value,
            'device_type' => 'android',
            'device_os' => 'android',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function fakeUnregisteredResponseAfterRotation(
        int $tokenId,
        string $replacement
    ): void {
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('send')->once()->andReturnUsing(
            function () use ($tokenId, $replacement): never {
                DB::table('user_device_tokens')->where('id', $tokenId)->update([
                    'device_token' => $replacement,
                    'updated_at' => now(),
                ]);
                throw new NotFound('The stale registration token is not registered.');
            }
        );
        $this->app->instance(Messaging::class, $messaging);
    }
}
