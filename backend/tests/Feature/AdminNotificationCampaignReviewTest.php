<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Jobs\SendStudentNotification;
use App\Models\NotificationCampaign;
use App\Models\NotificationCampaignRecipient;
use App\Models\User;
use App\Services\NotificationCampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminNotificationCampaignReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->withoutMiddleware(RequireAdminMfa::class);
    }

    public function test_broadcast_form_has_no_default_audience_and_requires_review(): void
    {
        $this->actingAs($this->user('admin'))
            ->get(route('admin.notifications.create'))
            ->assertOk()
            ->assertSee('<option value="" selected>اختر الجمهور</option>', false)
            ->assertDontSee('<option value="all" selected', false)
            ->assertSee('name="confirm_send"', false)
            ->assertSee(route('admin.notifications.audience-preview'), false)
            ->assertSee(route('admin.notifications.test'), false);
    }

    public function test_broadcast_is_refused_without_audience_or_confirmation(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)
            ->post(route('admin.notifications.store'), $this->payload(['audience' => null]))
            ->assertSessionHasErrors('audience');
        $this->actingAs($admin)
            ->post(route('admin.notifications.store'), $this->payload(['confirm_send' => null]))
            ->assertSessionHasErrors('confirm_send');
        self::assertSame(0, NotificationCampaign::query()->count());

        $this->actingAs($admin)
            ->post(route('admin.notifications.store'), $this->payload())
            ->assertRedirect(route('admin.notifications.index'));
        self::assertSame(1, NotificationCampaign::query()->count());
        Queue::assertPushed(SendStudentNotification::class, 1);
    }

    public function test_recipient_preview_uses_delivery_eligibility(): void
    {
        $this->student(['marketing_notifications_enabled' => true]);
        $this->student(['marketing_notifications_enabled' => false]);
        $this->student(['active' => false, 'marketing_notifications_enabled' => true]);
        $admin = $this->user('admin');

        $this->actingAs($admin)
            ->getJson(route('admin.notifications.audience-preview', ['audience' => 'all', 'notification_kind' => 'service']))
            ->assertOk()->assertExactJson(['recipients' => 2]);
        $this->actingAs($admin)
            ->getJson(route('admin.notifications.audience-preview', ['audience' => 'all', 'notification_kind' => 'marketing']))
            ->assertOk()->assertExactJson(['recipients' => 1]);
        $this->actingAs($admin)
            ->getJson(route('admin.notifications.audience-preview', ['audience' => 'enrolled']))
            ->assertStatus(422)->assertJsonValidationErrors('course_id');
    }

    public function test_test_send_reaches_only_the_named_learner_and_is_marked(): void
    {
        $tester = $this->student(['email' => 'tester@rokn.test']);
        $this->student();
        $admin = $this->user('admin');

        $this->actingAs($admin)
            ->postJson(route('admin.notifications.test'), $this->payload(['test_email' => 'TESTER@rokn.test', 'confirm_send' => null]))
            ->assertOk()->assertJsonPath('message', 'أُرسلت نسخة تجريبية إلى tester@rokn.test');
        $campaign = NotificationCampaign::query()->sole();
        self::assertSame([(int) $tester->id], array_map('intval', (array) $campaign->user_ids));
        self::assertSame('admin_message', $campaign->notification_type);
        self::assertStringStartsWith('تجربة: ', $campaign->title_ar);

        $this->actingAs($admin)
            ->postJson(route('admin.notifications.test'), $this->payload(['test_email' => 'nobody@rokn.test']))
            ->assertStatus(422)->assertJsonValidationErrors('test_email');
        self::assertSame(1, NotificationCampaign::query()->count());
    }

    public function test_scheduled_campaign_can_be_cancelled_and_is_never_released(): void
    {
        $campaign = $this->campaign(['status' => NotificationCampaign::STATUS_SCHEDULED, 'scheduled_at' => now()->subMinute()]);

        $this->actingAs($this->user('admin'))
            ->post(route('admin.notifications.cancel', $campaign))
            ->assertRedirect(route('admin.notifications.index'))
            ->assertSessionHas('success');
        self::assertSame(NotificationCampaign::STATUS_CANCELLED, $campaign->fresh()->status);

        $this->artisan('notifications:dispatch-scheduled')->assertExitCode(0);
        Queue::assertNotPushed(SendStudentNotification::class);

        // A coordinator already sitting in the queue must not claim it either.
        (new SendStudentNotification((string) $campaign->delivery_key))->handle();
        self::assertSame(NotificationCampaign::STATUS_CANCELLED, $campaign->fresh()->status);
        self::assertSame(0, NotificationCampaignRecipient::query()->count());
    }

    public function test_started_campaigns_cannot_be_half_cancelled(): void
    {
        $service = app(NotificationCampaignService::class);
        $delivering = $this->campaign(['status' => NotificationCampaign::STATUS_DELIVERING, 'selection_cursor' => 7]);
        $retried = $this->campaign(['status' => NotificationCampaign::STATUS_QUEUED]);
        NotificationCampaignRecipient::query()->create([
            'notification_campaign_id' => $retried->id,
            'user_id' => $this->student()->id,
            'status' => NotificationCampaignRecipient::STATUS_INBOX,
        ]);
        $fresh = $this->campaign(['status' => NotificationCampaign::STATUS_QUEUED]);

        self::assertFalse($service->cancel($delivering));
        self::assertFalse($service->cancel($retried));
        self::assertTrue($service->cancel($fresh));
        self::assertSame(NotificationCampaign::STATUS_DELIVERING, $delivering->fresh()->status);
        self::assertSame(NotificationCampaign::STATUS_QUEUED, $retried->fresh()->status);
        self::assertSame(NotificationCampaign::STATUS_CANCELLED, $fresh->fresh()->status);

        $this->actingAs($this->user('admin'))
            ->post(route('admin.notifications.cancel', $delivering))
            ->assertSessionHas('warning');
    }

    public function test_moderators_cannot_preview_test_or_cancel(): void
    {
        $moderator = $this->user('moderator');
        $campaign = $this->campaign(['status' => NotificationCampaign::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay()]);

        $this->actingAs($moderator)->getJson(route('admin.notifications.audience-preview', ['audience' => 'all']))->assertForbidden();
        $this->actingAs($moderator)->post(route('admin.notifications.test'), $this->payload(['test_email' => 'x@rokn.test']))->assertForbidden();
        $this->actingAs($moderator)->post(route('admin.notifications.cancel', $campaign))->assertForbidden();
        self::assertSame(NotificationCampaign::STATUS_SCHEDULED, $campaign->fresh()->status);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_filter([
            'authoring_request_id' => (string) Str::uuid(),
            'title_ar' => 'عنوان الإشعار',
            'message_ar' => 'رسالة الإشعار',
            'audience' => 'all',
            'notification_kind' => 'service',
            'confirm_send' => '1',
            ...$overrides,
        ], static fn ($value): bool => $value !== null);
    }

    /** @param array<string, mixed> $values */
    private function campaign(array $values): NotificationCampaign
    {
        return NotificationCampaign::query()->create([
            'delivery_key' => 'test-'.Str::uuid(),
            'notification_type' => 'service_notice',
            'audience' => 'all',
            'title_ar' => 'حملة', 'title_en' => 'Campaign',
            'message_ar' => 'نص', 'message_en' => 'Body',
            'user_ids' => [], 'exclude_user_ids' => [],
            ...$values,
        ]);
    }

    /** @param array<string, mixed> $values */
    private function student(array $values = []): User
    {
        return User::query()->forceCreate([
            'name' => 'Student', 'email' => Str::uuid().'@rokn.test', 'role' => 'client', 'active' => true,
            ...$values,
        ]);
    }

    private function user(string $role): User
    {
        return User::query()->forceCreate([
            'name' => 'Staff', 'email' => Str::uuid().'@rokn.test', 'role' => $role, 'active' => true,
        ]);
    }
}
