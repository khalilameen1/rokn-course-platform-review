<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendUserPushNotification;
use App\Models\AdminNotification;
use App\Models\StudentNotification;
use App\Models\User;
use App\Services\StudentNotificationService;
use App\Support\StudentNotificationIntent;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class StudentNotificationIntentWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Bus::fake();
    }

    public function test_named_fields_keep_authored_copy_and_dispatch_only_a_persisted_receipt_id(): void
    {
        $user = $this->student();
        $receipt = app(StudentNotificationService::class)->notifyUser($user, $this->intent());
        self::assertNotNull($receipt);
        self::assertSame('direct:one', $receipt->delivery_key);
        self::assertSame('service_notice', $receipt->notification_type);
        self::assertSame('عنوان عربي', $receipt->title_ar);
        self::assertSame('English title', $receipt->title_en);
        self::assertSame("سطر أول\nسطر ثان", $receipt->message_ar);
        self::assertSame('Distinct English message', $receipt->message_en);
        self::assertSame('rokn://wallet', $receipt->link);
        self::assertSame('https://cdn.example.test/receipt.png', $receipt->image_url);
        Bus::assertDispatched(SendUserPushNotification::class,
            fn ($job) => $job->uniqueId() === 'notification:' . $receipt->id);
        Bus::assertDispatchedTimes(SendUserPushNotification::class, 1);
    }

    public function test_replay_does_not_rewrite_the_first_receipt_or_push_it_twice(): void
    {
        $service = app(StudentNotificationService::class);
        $user = $this->student();
        $receipt = $service->notifyUser($user, $this->intent());
        $before = $receipt->fresh()->getAttributes();
        $replay = $service->notifyUser($user, $this->intent([
            'titleAr' => 'عنوان معدّل', 'link' => 'rokn://home', 'imageUrl' => null,
        ]));
        self::assertSame($receipt->id, $replay->id);
        self::assertSame($before, $replay->getAttributes());
        self::assertSame(1, StudentNotification::query()->count());
        Bus::assertDispatchedTimes(SendUserPushNotification::class, 1);
    }

    public function test_delivery_identity_is_per_user_not_global(): void
    {
        $service = app(StudentNotificationService::class);
        $first = $service->notifyUser($this->student(), $this->intent());
        $second = $service->notifyUser($this->student('other'), $this->intent());
        self::assertNotSame($first->id, $second->id);
        self::assertSame($first->delivery_key, $second->delivery_key);
        self::assertSame(2, StudentNotification::query()->count());
        Bus::assertDispatchedTimes(SendUserPushNotification::class, 2);
    }

    public function test_long_keys_are_hashed_and_missing_keys_get_independent_receipts(): void
    {
        $service = app(StudentNotificationService::class);
        $user = $this->student();
        $key = str_repeat('key:', 24);
        $receipt = $service->notifyUser($user, $this->intent(['deliveryKey' => ' ' . $key . ' ']));
        self::assertSame(hash('sha256', $key), $receipt->delivery_key);
        $first = $service->notifyUser($user, $this->intent(['deliveryKey' => null]));
        $second = $service->notifyUser($user, $this->intent(['deliveryKey' => null]));
        self::assertTrue(\Illuminate\Support\Str::isUuid($first->delivery_key));
        self::assertNotSame($first->delivery_key, $second->delivery_key);
        Bus::assertDispatchedTimes(SendUserPushNotification::class, 3);
    }

    public function test_disabled_template_does_not_create_a_receipt_or_a_push(): void
    {
        AdminNotification::query()->where('system_key', 'coins_claimed')->update(['is_active' => false]);
        self::assertNull(app(StudentNotificationService::class)->notifyUser(
            $this->student(), $this->intent(['notificationType' => 'coins_claimed'])
        ));
        self::assertSame(0, StudentNotification::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_template_variables_and_actions_are_rendered_without_losing_authored_link(): void
    {
        AdminNotification::query()->where('system_key', 'coins_claimed')->update([
            'is_active' => true, 'title_ar' => 'وصلت مكافأتك', 'title_en' => 'Your reward',
            'description_ar' => '{coins} عملة', 'description_en' => '{coins} coins',
            'action_label_ar' => 'افتح الرصيد', 'action_label_en' => 'See balance',
            'link' => 'rokn://home',
        ]);
        $receipt = app(StudentNotificationService::class)->notifyUser($this->student(), $this->intent([
            'notificationType' => 'coins_claimed', 'templateVariables' => ['coins' => 17],
        ]));
        self::assertSame('١٧ عملة', $receipt->message_ar);
        self::assertSame('17 coins', $receipt->message_en);
        self::assertSame('افتح الرصيد', $receipt->action_label_ar);
        self::assertSame('See balance', $receipt->action_label_en);
        self::assertSame('rokn://wallet', $receipt->link);
    }

    public function test_current_recipient_state_is_rechecked_for_stale_callbacks(): void
    {
        $service = app(StudentNotificationService::class);
        $user = $this->student();
        User::query()->whereKey($user->id)->update(['active' => false]);
        self::assertNull($service->notifyUser($user, $this->intent()));
        User::query()->whereKey($user->id)->update(['active' => true, 'role' => 'admin']);
        self::assertNull($service->notifyUser($user, $this->intent()));
        User::query()->whereKey($user->id)->update(['role' => 'client']);
        $user->delete();
        self::assertNull($service->notifyUser($user, $this->intent()));
        self::assertSame(0, StudentNotification::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_marketing_opt_out_does_not_hide_transactional_inbox_receipts(): void
    {
        $service = app(StudentNotificationService::class);
        $user = $this->student();
        $user->forceFill(['marketing_notifications_enabled' => false, 'notifications_status' => false])->save();
        self::assertNull($service->notifyUser($user, $this->intent(['notificationType' => 'course_promotion'])));
        self::assertNotNull($service->notifyUser($user, $this->intent()));
        self::assertSame(1, StudentNotification::query()->count());
        // The worker, not admission, rechecks permission to wake a device.
        Bus::assertDispatchedTimes(SendUserPushNotification::class, 1);
    }

    public function test_parent_rollback_cancels_both_the_receipt_and_its_after_commit_push(): void
    {
        $user = $this->student();
        DB::beginTransaction();
        try {
            self::assertNotNull(app(StudentNotificationService::class)->notifyUser($user, $this->intent()));
            Bus::assertNothingDispatched();
        } finally {
            DB::rollBack();
        }
        self::assertSame(0, StudentNotification::query()->count());
        Bus::assertNothingDispatched();
    }

    private function student(string $suffix = 'one'): User
    {
        return User::query()->forceCreate([
            'name' => 'Inbox Student', 'email' => 'inbox-' . $suffix . '@rokn.test',
            'role' => 'client', 'active' => true,
        ]);
    }

    private function intent(array $overrides = []): StudentNotificationIntent
    {
        return new StudentNotificationIntent(...array_replace([
            'notificationType' => 'service_notice', 'titleAr' => 'عنوان عربي',
            'titleEn' => 'English title', 'messageAr' => "سطر أول\nسطر ثان",
            'messageEn' => 'Distinct English message', 'link' => 'rokn://wallet',
            'deliveryKey' => ' direct:one ', 'imageUrl' => 'https://cdn.example.test/receipt.png',
        ], $overrides));
    }
}
