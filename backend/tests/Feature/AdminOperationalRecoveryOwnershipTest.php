<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\ProductOperationsController;
use App\Jobs\DeliverOutboxEvent;
use App\Models\OutboxEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\AdminOperationalRecoveryService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class AdminOperationalRecoveryOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Http::preventStrayRequests();
        Bus::fake();
        $this->app->bind(ProductOperationsController::class, static function (): never {
            throw new \LogicException('Operational commands must not resolve their HTTP adapter.');
        });
    }

    public function test_retry_preserves_identity_and_waits_for_outer_commit(): void
    {
        $event = $this->event();
        $failed = $this->delivery($event, WebhookDelivery::STATUS_FAILED);
        $delivered = $this->delivery($event, WebhookDelivery::STATUS_DELIVERED);
        $otherEvent = $this->event();
        $other = $this->delivery($otherEvent, WebhookDelivery::STATUS_FAILED);
        $deliveredBefore = $delivered->fresh()->getRawOriginal();
        $otherBefore = $other->fresh()->getRawOriginal();

        DB::transaction(function () use ($event, $failed): void {
            app(AdminOperationalRecoveryService::class)->retryOutbox($event->id, 9, 'Retry after provider recovery');
            self::assertSame(OutboxEvent::STATUS_PENDING, $event->fresh()->status);
            self::assertNull($event->fresh()->dispatched_at);
            self::assertSame(WebhookDelivery::STATUS_PENDING, $failed->fresh()->status);
            Bus::assertNothingDispatched();
        });

        Bus::assertDispatched(DeliverOutboxEvent::class, fn ($job) => $job->outboxEventId === $event->id);
        Bus::assertDispatchedTimes(DeliverOutboxEvent::class, 1);
        self::assertSame(2, OutboxEvent::query()->count());
        self::assertSame($event->event_key, $event->fresh()->event_key);
        self::assertSame($event->payload, $event->fresh()->payload);
        self::assertSame(4, $event->fresh()->attempts);
        self::assertNotNull($event->fresh()->dispatched_at);
        self::assertNull($failed->fresh()->response_code);
        self::assertNull($failed->fresh()->error_fingerprint);
        self::assertSame($deliveredBefore, $delivered->fresh()->getRawOriginal());
        self::assertSame($otherBefore, $other->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_outer_rollback_leaves_failure_and_does_not_dispatch(): void
    {
        $event = $this->event();
        $delivery = $this->delivery($event, WebhookDelivery::STATUS_FAILED);
        $before = $event->fresh()->getRawOriginal();
        try {
            DB::transaction(function () use ($event): void {
                app(AdminOperationalRecoveryService::class)->retryOutbox($event->id, 9, 'Retry provider delivery');
                throw new \RuntimeException('outer command failed');
            });
            self::fail('The outer transaction must roll back.');
        } catch (\RuntimeException $error) {
            self::assertSame('outer command failed', $error->getMessage());
        }
        self::assertSame($before, $event->fresh()->getRawOriginal());
        self::assertSame(WebhookDelivery::STATUS_FAILED, $delivery->fresh()->status);
        Bus::assertNothingDispatched();
    }

    public function test_retry_and_skip_reject_nonfailed_current_state(): void
    {
        $service = app(AdminOperationalRecoveryService::class);
        foreach (['pending', 'processing', 'blocked', 'delivered', 'skipped'] as $status) {
            $event = $this->event();
            $event->forceFill(['status' => $status])->save();
            foreach (['retryOutbox', 'skipOutbox'] as $command) {
                try {
                    $service->$command($event->id, 9, 'A stale operations page');
                    self::fail('Only currently failed events admit recovery.');
                } catch (HttpException $error) {
                    self::assertSame(409, $error->getStatusCode());
                }
                self::assertSame($status, $event->fresh()->status);
            }
        }
        Bus::assertNothingDispatched();
    }

    public function test_broker_failure_leaves_recoverable_pending_event(): void
    {
        $event = $this->event();
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('broker unavailable'));
        app(AdminOperationalRecoveryService::class)->retryOutbox($event->id, 9, 'Retry provider delivery');
        self::assertSame(OutboxEvent::STATUS_PENDING, $event->fresh()->status);
        self::assertNull($event->fresh()->dispatched_at);
        self::assertNotNull($event->fresh()->available_at);
    }

    public function test_skip_retains_identity_and_history_without_dispatch(): void
    {
        $event = $this->event();
        $delivery = $this->delivery($event, WebhookDelivery::STATUS_FAILED);
        app(AdminOperationalRecoveryService::class)->skipOutbox($event->id, 9, 'Confirmed obsolete event');
        $fresh = $event->fresh();
        self::assertSame(OutboxEvent::STATUS_SKIPPED, $fresh->status);
        self::assertSame($event->payload, $fresh->payload);
        self::assertSame(4, $fresh->attempts);
        self::assertNull($fresh->locked_at);
        self::assertNull($fresh->available_at);
        self::assertSame(WebhookDelivery::STATUS_FAILED, $delivery->fresh()->status);
        Bus::assertNothingDispatched();
    }

    public function test_acknowledgement_removes_only_named_job_without_replaying_payload(): void
    {
        $id = DB::table('failed_jobs')->insertGetId([
            'connection' => 'database', 'queue' => 'default',
            'payload' => 'not a deserializable job', 'exception' => 'test failure', 'failed_at' => now(),
        ]);
        $other = DB::table('failed_jobs')->insertGetId([
            'connection' => 'database', 'queue' => 'default',
            'payload' => 'another failure', 'exception' => 'test failure', 'failed_at' => now(),
        ]);
        app(AdminOperationalRecoveryService::class)->acknowledgeFailedJob($id, 9, 'Investigated without replay');
        self::assertFalse(DB::table('failed_jobs')->where('id', $id)->exists());
        self::assertTrue(DB::table('failed_jobs')->where('id', $other)->exists());
        Bus::assertNothingDispatched();
    }

    private function event(): OutboxEvent
    {
        return OutboxEvent::query()->create([
            'event_key' => (string) Str::uuid(), 'topic' => 'test.recovery',
            'aggregate_type' => 'course', 'aggregate_id' => '7', 'payload' => ['id' => 7],
            'status' => OutboxEvent::STATUS_FAILED, 'attempts' => 4,
            'available_at' => now(), 'locked_at' => now(),
            'last_error_fingerprint' => str_repeat('a', 64),
        ]);
    }

    private function delivery(OutboxEvent $event, string $status): WebhookDelivery
    {
        $endpoint = WebhookEndpoint::query()->create([
            'name' => 'Test endpoint', 'url' => 'https://example.test/webhook',
            'secret' => 'test-only', 'events' => ['*'], 'is_active' => true, 'timeout_seconds' => 10,
        ]);

        return WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $endpoint->id, 'outbox_event_id' => $event->id,
            'delivery_key' => (string) Str::uuid(), 'status' => $status, 'attempts' => 4,
            'response_code' => 503, 'error_fingerprint' => str_repeat('b', 64),
        ]);
    }
}
