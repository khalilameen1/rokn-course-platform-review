<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\NotificationAuthoringInput;
use App\Http\Controllers\Admin\NotificationsController;
use App\Http\Middleware\RequireAdminMfa;
use App\Jobs\SendStudentNotification;
use App\Models\NotificationCampaign;
use App\Models\User;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminNotificationCampaignAuthoringService;
use App\Support\PublicDiskUrl;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class NotificationAuthoringApplicationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Verify real after-commit dispatch and receipt rollback.
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_request_free_creation_owns_the_transaction_and_replay_does_not_queue_twice(): void
    {
        $author = $this->author();
        foreach ([NotificationsController::class, AdminAuthoringCreateIntentService::class] as $httpOwner) {
            $this->app->bind($httpOwner, static function (): never {
                throw new \LogicException('Notification authoring must not resolve HTTP owners.');
            });
        }
        $writer = app(AdminNotificationCampaignAuthoringService::class);
        $input = NotificationAuthoringInput::fromValidated((int) $author->id, $this->payload());
        $completed = [];
        $campaign = $writer->author($input, static function (?NotificationCampaign $saved) use (&$completed): void {
            self::assertNotNull($saved);
            self::assertSame(1, DB::transactionLevel());
            self::assertSame($saved->id, NotificationCampaign::query()->sole()->id);
            Queue::assertNotPushed(SendStudentNotification::class);
            $completed[] = $saved->id;
        });
        self::assertSame(0, DB::transactionLevel());
        Queue::assertPushed(SendStudentNotification::class, 1);
        $before = $campaign->getAttributes();
        $replay = $writer->author($input, static function (?NotificationCampaign $saved) use (&$completed): void {
            self::assertNotNull($saved);
            $completed[] = $saved->id;
        });
        self::assertSame([$campaign->id, $campaign->id], $completed);
        self::assertSame($campaign->id, $replay->id);
        self::assertSame($before, $replay->getAttributes());
        self::assertSame('service_notice', $campaign->notification_type);
        self::assertSame('عنوان الإشعار', $campaign->title_ar);
        self::assertSame('عنوان الإشعار', $campaign->title_en);
        self::assertSame('رسالة الإشعار', $campaign->message_en);
        self::assertSame((int) $author->id, (int) $campaign->authored_by);
        self::assertSame(1, NotificationCampaign::query()->count());
        Queue::assertPushed(SendStudentNotification::class, 1);
        Http::assertNothingSent();
    }

    public function test_receipt_failure_rolls_back_campaign_and_queue_before_same_input_can_retry(): void
    {
        $input = NotificationAuthoringInput::fromValidated((int) $this->author()->id, $this->payload());
        $writer = app(AdminNotificationCampaignAuthoringService::class);
        try {
            $writer->author($input, static function (?NotificationCampaign $saved): never {
                self::assertNotNull($saved);
                self::assertSame(1, NotificationCampaign::query()->count());
                throw new \RuntimeException('receipt unavailable');
            });
            self::fail('Receipt failure must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('receipt unavailable', $error->getMessage());
        }
        self::assertSame(0, NotificationCampaign::query()->count());
        self::assertSame(0, DB::transactionLevel());
        Queue::assertNotPushed(SendStudentNotification::class);

        $completedId = null;
        $campaign = $writer->author($input, static function (?NotificationCampaign $saved) use (&$completedId): void {
            $completedId = $saved?->id;
        });
        self::assertSame($campaign->id, $completedId);
        self::assertSame(1, NotificationCampaign::query()->count());
        Queue::assertPushed(SendStudentNotification::class, 1);
    }

    public function test_inactive_recipient_and_missing_author_cannot_reach_the_completion_callback(): void
    {
        $author = $this->author();
        $recipient = User::query()->forceCreate(['name_ar' => 'الطالب', 'role' => 'client', 'active' => false]);
        $writer = app(AdminNotificationCampaignAuthoringService::class);
        $complete = static function (?NotificationCampaign $saved): never {
            self::fail('Rejected input must not be completed.');
        };
        try {
            $writer->author(NotificationAuthoringInput::fromValidated((int) $author->id, [
                ...$this->payload(), 'user_id' => $recipient->id,
            ]), $complete);
            self::fail('Inactive recipient was accepted.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('user_id', $error->errors());
        }
        try {
            $writer->author(NotificationAuthoringInput::fromValidated(0, $this->payload()), $complete);
            self::fail('Missing author was accepted.');
        } catch (HttpExceptionInterface $error) {
            self::assertSame(403, $error->getStatusCode());
        }
        self::assertSame(0, NotificationCampaign::query()->count());
        Queue::assertNotPushed(SendStudentNotification::class);
    }

    public function test_http_receipt_failure_then_retry_persists_one_campaign_and_one_completed_receipt(): void
    {
        $this->actingAs($this->author(), 'web')->withoutMiddleware(RequireAdminMfa::class);
        $payload = $this->payload();
        $this->withoutExceptionHandling();
        DB::statement("CREATE TRIGGER reject_notification_application_receipt BEFORE UPDATE ON admin_authoring_create_intents
            WHEN NEW.status = 'completed' BEGIN SELECT RAISE(ABORT, 'receipt unavailable'); END");
        try {
            $this->post(route('admin.notifications.store'), $payload);
            self::fail('Receipt storage failure must not report a completed request.');
        } catch (QueryException $error) {
            self::assertStringContainsString('receipt unavailable', $error->getMessage());
        } finally {
            DB::statement('DROP TRIGGER reject_notification_application_receipt');
        }
        self::assertSame(0, NotificationCampaign::query()->count());
        self::assertSame('failed', DB::table('admin_authoring_create_intents')->value('status'));
        Queue::assertNotPushed(SendStudentNotification::class);

        $this->post(route('admin.notifications.store'), $payload)->assertRedirect(route('admin.notifications.index'));
        $campaign = NotificationCampaign::query()->sole();
        $receipt = DB::table('admin_authoring_create_intents')->sole();
        self::assertSame('completed', $receipt->status);
        self::assertSame('redirect', $receipt->response_kind);
        self::assertSame((string) $campaign->id, (string) $receipt->resource_id);
        $this->post(route('admin.notifications.store'), $payload)->assertRedirect(route('admin.notifications.index'));
        self::assertSame(1, NotificationCampaign::query()->count());
        Queue::assertPushed(SendStudentNotification::class, 1);
        Http::assertNothingSent();
    }

    public function test_http_image_and_authenticated_author_reach_the_campaign_and_replay_once(): void
    {
        Storage::fake('public', ['url' => 'https://rokn.test/storage']);
        $author = $this->author();
        $this->actingAs($author, 'web')->withoutMiddleware(RequireAdminMfa::class);
        $image = UploadedFile::fake()->image('notice.png', 120, 80);
        $payload = [
            ...$this->payload(),
            // Match a real multipart upload. Laravel's fake has a public stream
            // resource that real UploadedFile objects never include in payloads.
            'image' => new UploadedFile($image->getPathname(), 'notice.png', 'image/png', null, true),
            'authored_by' => 999, 'author_id' => 888,
        ];
        $this->post(route('admin.notifications.store'), $payload)->assertRedirect(route('admin.notifications.index'));
        $campaign = NotificationCampaign::query()->sole();
        self::assertSame((int) $author->id, (int) $campaign->authored_by);
        $path = PublicDiskUrl::pathFrom($campaign->image_url);
        self::assertNotNull($path);
        Storage::disk('public')->assertExists($path);
        self::assertSame('completed', DB::table('admin_authoring_create_intents')->value('status'));

        $this->post(route('admin.notifications.store'), $payload)->assertRedirect(route('admin.notifications.index'));
        self::assertSame(1, NotificationCampaign::query()->count());
        self::assertSame([$path], Storage::disk('public')->files('student-notifications'));
        Queue::assertPushed(SendStudentNotification::class, 1);
        Http::assertNothingSent();
    }

    public function test_explicit_local_schedule_survives_replay_and_cannot_be_replaced(): void
    {
        $this->travelTo(Carbon::parse('2026-09-25 12:00:00', 'Africa/Cairo'));
        try {
            $authorId = (int) $this->author()->id;
            $payload = [...$this->payload(), 'send_at' => '2026-10-01T14:15'];
            $input = NotificationAuthoringInput::fromValidated($authorId, $payload);
            $writer = app(AdminNotificationCampaignAuthoringService::class);
            $complete = static function (?NotificationCampaign $saved): void { self::assertNotNull($saved); };
            $campaign = $writer->author($input, $complete);
            self::assertSame(NotificationCampaign::STATUS_SCHEDULED, $campaign->status);
            self::assertSame(
                Carbon::parse('2026-10-01 14:15:00', 'Africa/Cairo')->utc()->toDateTimeString(),
                $campaign->scheduled_at->toDateTimeString()
            );
            self::assertSame($campaign->id, $writer->author($input, $complete)->id);
            try {
                $writer->author(NotificationAuthoringInput::fromValidated($authorId, [
                    ...$payload, 'send_at' => '2026-10-02T14:15',
                ]), $complete);
                self::fail('A replay replaced the authored schedule.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('authoring_request_id', $error->errors());
            }
            self::assertSame(1, NotificationCampaign::query()->count());
            self::assertSame($campaign->scheduled_at->toDateTimeString(), $campaign->fresh()->scheduled_at->toDateTimeString());
            Queue::assertNotPushed(SendStudentNotification::class);
        } finally {
            $this->travelBack();
        }
    }

    private function author(): User
    {
        return User::query()->forceCreate(['name_ar' => 'المسؤول', 'role' => 'admin', 'active' => true]);
    }

    private function payload(): array
    {
        return [
            'authoring_request_id' => (string) Str::uuid(), 'title_ar' => 'عنوان الإشعار',
            'message_ar' => 'رسالة الإشعار', 'audience' => 'all', 'notification_kind' => 'service',
        ];
    }
}
