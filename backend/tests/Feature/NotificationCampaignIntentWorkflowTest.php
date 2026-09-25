<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendStudentNotification;
use App\Models\NotificationCampaign;
use App\Services\NotificationCampaignService;
use App\Support\NotificationAudience;
use App\Support\NotificationCampaignIntent;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class NotificationCampaignIntentWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // No outer test transaction: exercise actual after-commit/rollback behavior.
        $this->artisan('migrate:fresh')->assertExitCode(0);
        $this->travelTo(Carbon::parse('2026-09-25 12:00:00', 'Africa/Cairo'));
        Bus::fake();
        Http::fake(static fn () => throw new \LogicException('Campaign admission cannot deliver a push.'));
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
        } finally {
            $this->travelBack();
            parent::tearDown();
        }
    }

    public function test_named_input_persists_distinct_copy_and_audience_and_dispatches_only_its_key(): void
    {
        $intent = $this->intent([
            'deliveryKey' => '  campaign:one  ',
            'audience' => new NotificationAudience(
                selector: NotificationAudience::ALL, userIds: [9, 3, 9], excludeUserIds: [8, 2, 8]
            ),
        ]);
        self::assertTrue(app(NotificationCampaignService::class)->queue($intent));
        $campaign = NotificationCampaign::query()->sole();
        self::assertSame('campaign:one', $campaign->delivery_key);
        self::assertSame('service_notice', $campaign->notification_type);
        self::assertSame('عنوان عربي', $campaign->title_ar);
        self::assertSame('English title', $campaign->title_en);
        self::assertSame("سطر أول\nسطر ثان", $campaign->message_ar);
        self::assertSame('A different English message', $campaign->message_en);
        self::assertSame([3, 9], $campaign->user_ids);
        self::assertSame([2, 8], $campaign->exclude_user_ids);
        self::assertSame('rokn://wallet', $campaign->link);
        self::assertSame('https://cdn.example.test/authored.png', $campaign->image_url);
        self::assertSame('افتح الرصيد', $campaign->action_label_ar);
        self::assertSame('See balance', $campaign->action_label_en);
        self::assertSame(NotificationCampaign::STATUS_QUEUED, $campaign->status);
        Bus::assertDispatchedTimes(SendStudentNotification::class, 1);
        Bus::assertDispatched(SendStudentNotification::class, fn ($job) => $job->uniqueId() === $intent->deliveryKey);
    }

    public function test_replay_preserves_the_committed_presentation_even_if_links_and_labels_change(): void
    {
        $service = app(NotificationCampaignService::class);
        self::assertTrue($service->queue($this->intent()));
        $before = NotificationCampaign::query()->sole()->getAttributes();
        self::assertFalse($service->queue($this->intent([
            'link' => 'rokn://home',
            'actionLabelAr' => 'افتح ركن',
            'actionLabelEn' => 'Open Rokn',
            'imageUrl' => null,
            'audience' => new NotificationAudience(selector: NotificationAudience::ALL, userIds: [3, 9, 3]),
        ])));
        self::assertSame($before, NotificationCampaign::query()->sole()->getAttributes());
        Bus::assertDispatchedTimes(SendStudentNotification::class, 1);
    }

    public static function conflictingIntents(): array
    {
        return [
            'Arabic title' => ['titleAr', 'عنوان مختلف'],
            'English title' => ['titleEn', 'Changed title'],
            'Arabic body' => ['messageAr', 'رسالة أخرى'],
            'English body' => ['messageEn', 'Changed message'],
            'type' => ['notificationType', 'account_notice'],
            'explicit image' => ['imageUrl', 'https://cdn.example.test/different.png'],
        ];
    }

    #[DataProvider('conflictingIntents')]
    public function test_replay_cannot_replace_immutable_authored_content(string $field, string $value): void
    {
        $service = app(NotificationCampaignService::class);
        $service->queue($this->intent());
        $before = NotificationCampaign::query()->sole()->getAttributes();
        try {
            $service->queue($this->intent([$field => $value]));
            self::fail('A conflicting campaign reused an existing delivery key.');
        } catch (\DomainException $exception) {
            self::assertSame('notification_delivery_key_payload_mismatch', $exception->getMessage());
        }
        self::assertSame($before, NotificationCampaign::query()->sole()->getAttributes());
        Bus::assertDispatchedTimes(SendStudentNotification::class, 1);
    }

    public function test_replay_cannot_expand_or_change_excluded_recipients(): void
    {
        $service = app(NotificationCampaignService::class);
        $service->queue($this->intent());
        $before = NotificationCampaign::query()->sole()->getAttributes();
        foreach ([
            new NotificationAudience(selector: NotificationAudience::ALL, userIds: [3, 9, 10]),
            new NotificationAudience(selector: NotificationAudience::ALL, userIds: [3, 9], excludeUserIds: [3]),
        ] as $audience) {
            try {
                $service->queue($this->intent(['audience' => $audience]));
                self::fail('Recipient identity changed during a replay.');
            } catch (\DomainException $exception) {
                self::assertSame('notification_delivery_key_payload_mismatch', $exception->getMessage());
            }
        }
        self::assertSame($before, NotificationCampaign::query()->sole()->getAttributes());
        Bus::assertDispatchedTimes(SendStudentNotification::class, 1);
    }

    public function test_requested_schedule_is_frozen_and_quiet_hours_are_applied_only_at_queue_time(): void
    {
        $requested = Carbon::parse('2026-09-25 23:30:00', 'Africa/Cairo');
        $intent = $this->intent(['notificationType' => 'admin_broadcast', 'scheduledAt' => $requested]);
        $requested->addDays(5);
        app(NotificationCampaignService::class)->queue($intent);
        $campaign = NotificationCampaign::query()->sole();
        self::assertSame('2026-09-25 23:30:00', $intent->scheduledAt->format('Y-m-d H:i:s'));
        self::assertSame(NotificationCampaign::STATUS_SCHEDULED, $campaign->status);
        self::assertNull($campaign->queued_at);
        self::assertSame(
            Carbon::parse('2026-09-26 09:00:00', 'Africa/Cairo')->utc()->toDateTimeString(),
            $campaign->scheduled_at->toDateTimeString()
        );
        Bus::assertNothingDispatched();
    }

    public function test_rollback_discards_the_campaign_and_does_not_dispatch_a_job(): void
    {
        DB::beginTransaction();
        try {
            app(NotificationCampaignService::class)->queue($this->intent());
            self::assertSame(1, NotificationCampaign::query()->count());
            Bus::assertNothingDispatched();
        } finally {
            DB::rollBack();
        }
        self::assertSame(0, NotificationCampaign::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_broker_failure_preserves_the_campaign_and_retry_uses_the_same_delivery_identity(): void
    {
        $this->mock(Dispatcher::class)->shouldReceive('dispatch')->once()
            ->andThrow(new \RuntimeException('test queue unavailable'));
        $service = app(NotificationCampaignService::class);
        self::assertTrue($service->queue($this->intent()));
        $campaign = NotificationCampaign::query()->sole();
        self::assertSame(NotificationCampaign::STATUS_FAILED, $campaign->status);
        self::assertStringStartsWith('queue_', $campaign->failure_code);
        self::assertSame('https://cdn.example.test/authored.png', $campaign->image_url);

        Bus::fake();
        self::assertTrue($service->retry($campaign));
        self::assertFalse($service->retry($campaign));
        self::assertSame(NotificationCampaign::STATUS_QUEUED, $campaign->fresh()->status);
        Bus::assertDispatchedTimes(SendStudentNotification::class, 1);
        Bus::assertDispatched(SendStudentNotification::class, fn ($job) => $job->uniqueId() === 'intent-workflow');
    }

    private function intent(array $overrides = []): NotificationCampaignIntent
    {
        return new NotificationCampaignIntent(...array_replace([
            'notificationType' => 'service_notice',
            'deliveryKey' => 'intent-workflow',
            'audience' => new NotificationAudience(selector: NotificationAudience::ALL, userIds: [9, 3]),
            'titleAr' => 'عنوان عربي',
            'titleEn' => 'English title',
            'messageAr' => "سطر أول\nسطر ثان",
            'messageEn' => 'A different English message',
            'link' => 'rokn://wallet',
            'imageUrl' => 'https://cdn.example.test/authored.png',
            'actionLabelAr' => 'افتح الرصيد',
            'actionLabelEn' => 'See balance',
        ], $overrides));
    }
}
