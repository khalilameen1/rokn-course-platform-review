<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeliverOutboxEvent;
use App\Jobs\GenerateCourseChatReply;
use App\Jobs\GenerateProjectFeedback;
use App\Jobs\GenerateProjectFeedbackReply;
use App\Models\AiUsageEvent;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use App\Services\AiConsentService;
use App\Services\PaidAiCallExecutionService;
use Carbon\CarbonImmutable;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductionQueueTopologyTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_chat_worker_keeps_landing_headroom_after_provider_timeout(): void
    {
        Queue::fake();
        config()->set('openrouter.timeout_seconds', 45);
        $job = new GenerateCourseChatReply(
            1,
            1,
            'openai/gpt-5-mini',
            [['role' => 'user', 'content' => 'question']],
            .3,
            420,
            []
        );
        dispatch($job);

        Queue::assertPushedOn('ai-chat', GenerateCourseChatReply::class);
        self::assertGreaterThanOrEqual(
            (int) config('openrouter.timeout_seconds', 45) + 30,
            $job->timeout
        );
        // The documented worker serves that queue and outlives the job.
        self::assertGreaterThan($job->timeout, $this->workerTimeout('ai-chat'));
        self::assertStringContainsString(
            '--queue=ai-chat --sleep=1 --tries=3 --timeout=90',
            $this->runbook()
        );
    }

    public function test_ai_feedback_workers_outlive_provider_and_interactive_retry_is_short(): void
    {
        Queue::fake();
        config()->set('openrouter.timeout_seconds', 50);
        $report = new GenerateProjectFeedback(1);
        $reply = new GenerateProjectFeedbackReply(1);
        dispatch($report);
        dispatch($reply);

        Queue::assertPushedOn('ai-feedback', GenerateProjectFeedback::class);
        Queue::assertPushedOn('ai-feedback', GenerateProjectFeedbackReply::class);
        self::assertGreaterThanOrEqual(80, $report->timeout);
        self::assertGreaterThanOrEqual(80, $reply->timeout);
        self::assertSame([5, 20], $reply->backoff());
        self::assertGreaterThan(max($report->timeout, $reply->timeout), $this->workerTimeout('ai-feedback'));
        self::assertStringContainsString(
            '--queue=ai-feedback --sleep=1 --tries=3 --timeout=90',
            $this->runbook()
        );
    }

    public function test_each_paid_ai_worker_attempt_renews_its_reservation_lease(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00:00'));
        config()->set('openrouter.timeout_seconds', 45);
        config()->set('course_plans.ai_reservation_ttl_seconds', 120);
        $event = $this->reservedEvent(now()->addMinute());
        $calls = app(PaidAiCallExecutionService::class);

        // A queue retry can begin long after the original lease expired.
        $this->travel(10)->minutes();
        self::assertSame(PaidAiCallExecutionService::START, $calls->beginForActiveUser($event, 'attempt-1', (int) $event->user_id));
        $started = $event->fresh();
        self::assertSame('started', data_get($started->metadata, 'provider_call_state'));
        self::assertSame(1, data_get($started->metadata, 'provider_call_attempt'));
        self::assertSame(now()->addSeconds(120)->timestamp, $started->reservation_expires_at->timestamp);

        // A safe provider failure keeps the same reservation through backoff.
        $this->travel(30)->seconds();
        $calls->markRetrySafe($event, 'attempt-1');
        $retrySafe = $event->fresh();
        self::assertSame('retry_safe', data_get($retrySafe->metadata, 'provider_call_state'));
        self::assertSame('reserved', $retrySafe->status);
        self::assertSame(now()->addSeconds(120)->timestamp, $retrySafe->reservation_expires_at->timestamp);

        // The next owned attempt renews the lease again for the same request.
        $this->travel(5)->minutes();
        self::assertSame(PaidAiCallExecutionService::START, $calls->beginForActiveUser($event, 'attempt-2', (int) $event->user_id));
        $retried = $event->fresh();
        self::assertSame(2, data_get($retried->metadata, 'provider_call_attempt'));
        self::assertSame($event->request_id, $retried->request_id);
        self::assertSame(now()->addSeconds(120)->timestamp, $retried->reservation_expires_at->timestamp);
    }

    public function test_outbox_queue_has_a_dedicated_worker_contract(): void
    {
        Queue::fake();
        self::assertSame('webhooks', config('webhooks.queue'));
        $job = new DeliverOutboxEvent(1);
        dispatch($job);
        Queue::assertPushedOn('webhooks', DeliverOutboxEvent::class);
        self::assertGreaterThan($job->timeout, (int) config('queue.connections.redis.retry_after'));

        $runbook = $this->runbook();
        self::assertStringContainsString('--queue=webhooks', $runbook);
        self::assertGreaterThanOrEqual($job->timeout, $this->workerTimeout('webhooks'));
        self::assertStringContainsString('when `webhooks` exceeds two minutes', $runbook);

        $environment = $this->productionEnvironment();
        self::assertSame('webhooks', $environment['WEBHOOK_QUEUE'] ?? null);
        self::assertSame('360', $environment['REDIS_QUEUE_RETRY_AFTER'] ?? null);
        self::assertGreaterThan($job->timeout, (int) $environment['REDIS_QUEUE_RETRY_AFTER']);
    }

    public function test_production_template_selects_private_shared_course_pdf_storage(): void
    {
        $values = $this->productionEnvironment();

        self::assertSame('s3', $values['COURSE_PDF_DISK'] ?? null);
        self::assertSame('', $values['COURSE_PDF_STORAGE_PATH'] ?? null);
        self::assertSame('false', $values['COURSE_PDF_SHARED_STORAGE'] ?? null);
        self::assertArrayHasKey((string) $values['COURSE_PDF_DISK'], config('filesystems.disks'));
        self::assertNotSame(
            'local',
            config('filesystems.disks.'.(string) $values['COURSE_PDF_DISK'].'.driver')
        );
    }

    private function reservedEvent(\DateTimeInterface $expiresAt): AiUsageEvent
    {
        $user = new User;
        $user->forceFill([
            'name' => 'Lease learner',
            'email' => Str::uuid().'@test.rokn',
            'password' => bcrypt('test'),
            'role' => 'client',
            'active' => true,
        ])->save();
        app(AiConsentService::class)->record($user, true);
        $course = Course::factory()->make();
        $course->forceFill(['tenant_id' => 1])->save();
        $enrollment = new CourseEnrollment;
        $enrollment->forceFill([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'is_active' => true,
            'enrolled_at' => now(),
        ])->save();

        return AiUsageEvent::query()->create([
            'request_id' => (string) Str::uuid(),
            'enrollment_id' => $enrollment->id,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'feature' => 'project_feedback',
            'model' => 'test/model',
            'status' => 'reserved',
            'reserved_tokens' => 100,
            'reserved_cost_usd' => '0.020000',
            'reservation_expires_at' => $expiresAt,
        ]);
    }

    /** The documented `queue:work --timeout` for the worker serving one queue. */
    private function workerTimeout(string $queue): int
    {
        self::assertSame(
            1,
            preg_match('/--queue='.preg_quote($queue, '/').'\s[^`]*--timeout=(\d+)/', $this->runbook(), $matches),
            "The runbook must document one worker for {$queue}"
        );

        return (int) $matches[1];
    }

    private function runbook(): string
    {
        $runbook = file_get_contents(base_path('PRODUCTION_RUNBOOK.md'));
        self::assertIsString($runbook);

        return $runbook;
    }

    /** @return array<string,string|null> */
    private function productionEnvironment(): array
    {
        $environment = file_get_contents(base_path('.env.production.example'));
        self::assertIsString($environment);

        return Dotenv::parse($environment);
    }
}
