<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\DeliverOutboxEvent;
use App\Jobs\GenerateCourseChatReply;
use App\Jobs\GenerateProjectFeedback;
use App\Jobs\GenerateProjectFeedbackReply;
use Dotenv\Dotenv;
use Tests\TestCase;

final class ProductionQueueTopologyTest extends TestCase
{
    public function test_ai_chat_worker_keeps_landing_headroom_after_provider_timeout(): void
    {
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

        self::assertGreaterThanOrEqual(
            (int) config('openrouter.timeout_seconds', 45) + 30,
            $job->timeout
        );

        $runbook = file_get_contents(base_path('PRODUCTION_RUNBOOK.md'));
        self::assertIsString($runbook);
        self::assertStringContainsString(
            '--queue=ai-chat --sleep=1 --tries=3 --timeout=90',
            $runbook
        );
    }

    public function test_ai_feedback_workers_outlive_provider_and_interactive_retry_is_short(): void
    {
        config()->set('openrouter.timeout_seconds', 50);
        $report = new GenerateProjectFeedback(1);
        $reply = new GenerateProjectFeedbackReply(1);

        self::assertGreaterThanOrEqual(80, $report->timeout);
        self::assertGreaterThanOrEqual(80, $reply->timeout);
        self::assertSame([5, 20], $reply->backoff());

        $runbook = file_get_contents(base_path('PRODUCTION_RUNBOOK.md'));
        self::assertIsString($runbook);
        self::assertStringContainsString(
            '--queue=ai-feedback --sleep=1 --tries=3 --timeout=90',
            $runbook
        );
    }

    public function test_each_paid_ai_worker_attempt_renews_its_reservation_lease(): void
    {
        $source = file_get_contents(
            base_path('app/Services/PaidAiCallExecutionService.php')
        );

        self::assertIsString($source);
        self::assertGreaterThanOrEqual(
            2,
            substr_count(
                $source,
                "'reservation_expires_at' => now()->addSeconds(\$this->reservationLeaseSeconds())"
            )
        );
        self::assertStringContainsString(
            "\$metadata['provider_call_state'] = 'retry_safe'",
            $source
        );
    }

    public function test_outbox_queue_has_a_dedicated_worker_contract(): void
    {
        self::assertSame('webhooks', config('webhooks.queue'));
        self::assertGreaterThan(
            (new DeliverOutboxEvent(1))->timeout,
            (int) config('queue.connections.redis.retry_after')
        );

        $runbook = file_get_contents(base_path('PRODUCTION_RUNBOOK.md'));
        $environment = file_get_contents(base_path('.env.production.example'));

        self::assertIsString($runbook);
        self::assertStringContainsString('--queue=webhooks', $runbook);
        self::assertStringContainsString('when `webhooks` exceeds two minutes', $runbook);
        self::assertIsString($environment);
        self::assertStringContainsString('WEBHOOK_QUEUE=webhooks', $environment);
        self::assertStringContainsString('REDIS_QUEUE_RETRY_AFTER=360', $environment);
    }

    public function test_production_template_selects_private_shared_course_pdf_storage(): void
    {
        $environment = file_get_contents(base_path('.env.production.example'));
        self::assertIsString($environment);

        $values = Dotenv::parse($environment);

        self::assertSame('s3', $values['COURSE_PDF_DISK'] ?? null);
        self::assertSame('', $values['COURSE_PDF_STORAGE_PATH'] ?? null);
        self::assertSame('false', $values['COURSE_PDF_SHARED_STORAGE'] ?? null);
        self::assertArrayHasKey((string) $values['COURSE_PDF_DISK'], config('filesystems.disks'));
        self::assertNotSame(
            'local',
            config('filesystems.disks.'.(string) $values['COURSE_PDF_DISK'].'.driver')
        );
    }
}
