<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Logging\RedactSensitiveContext;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

final class RedactSensitiveContextTest extends TestCase
{
    public function test_it_redacts_nested_secrets_and_bearer_values_without_hiding_usage_metrics(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('privacy', [$handler]);
        (new RedactSensitiveContext())($logger);

        $logger->warning(
            'failed https://example.test/callback?signature=raw-signature Authorization: Bearer raw.token',
            [
                'email' => 'learner@example.test',
                'nested' => [
                    'device_token' => 'device-secret',
                    'transaction_id' => 'provider-financial-id',
                    'token_budget' => 1200,
                ],
            ]
        );

        $record = $handler->getRecords()[0];
        self::assertStringNotContainsString('raw-signature', $record->message);
        self::assertStringNotContainsString('raw.token', $record->message);
        self::assertSame('[redacted]', $record->context['email']);
        self::assertSame('[redacted]', $record->context['nested']['device_token']);
        self::assertSame('[redacted]', $record->context['nested']['transaction_id']);
        self::assertSame(1200, $record->context['nested']['token_budget']);
    }

    public function test_it_keeps_a_debuggable_exception_shape_without_raw_personal_data(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('privacy', [$handler]);
        (new RedactSensitiveContext())($logger);

        $logger->error('provider failed', [
            'exception' => new \RuntimeException('account learner@example.test failed'),
        ]);

        $exception = $handler->getRecords()[0]->context['exception'];
        self::assertSame(\RuntimeException::class, $exception['class']);
        self::assertStringNotContainsString('learner@example.test', $exception['message']);
        self::assertNotEmpty($exception['fingerprint']);
        self::assertArrayHasKey('trace', $exception);
        self::assertIsArray($exception['trace']);
    }

    public function test_it_removes_secret_assignments_and_exception_arguments_from_logs(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('privacy', [$handler]);
        (new RedactSensitiveContext())($logger);

        try {
            $this->throwWithSensitiveArgument('phone=201001234567 password:plain-secret');
        } catch (\Throwable $exception) {
            $logger->error('provider phone=201001234567 password:plain-secret', [
                'exception' => $exception,
            ]);
        }

        $record = $handler->getRecords()[0];
        $encoded = json_encode($record->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('201001234567', $encoded);
        self::assertStringNotContainsString('plain-secret', $encoded);
    }

    public function test_every_runtime_channel_uses_the_privacy_processor(): void
    {
        $config = require dirname(__DIR__, 2).'/config/logging.php';

        foreach (['stack', 'single', 'daily', 'slack', 'papertrail', 'stderr', 'syslog', 'errorlog'] as $channel) {
            self::assertContains(
                RedactSensitiveContext::class,
                $config['channels'][$channel]['tap'] ?? [],
                "{$channel} must redact sensitive context"
            );
        }
    }

    public function test_redeemable_codes_are_not_duplicated_into_free_form_financial_fields(): void
    {
        $courseCodes = file_get_contents(app_path('Models/CourseCode.php'));
        $coursePurchases = file_get_contents(
            app_path('Services/CoursePurchaseAction.php')
        );

        self::assertIsString($courseCodes);
        self::assertStringNotContainsString("'coupon_code' => \$this->code", $courseCodes);
        self::assertStringNotContainsString("'notes' => 'Course code redemption: '", $courseCodes);
        self::assertIsString($coursePurchases);
        $debitStart = strpos($coursePurchases, '$walletTransaction = $walletService->debit(');
        $debitEnd = strpos($coursePurchases, '// Course orders preserve');
        self::assertIsInt($debitStart, 'The shared purchase action must own the wallet debit');
        self::assertIsInt($debitEnd, 'The debit metadata must end before order attribution');
        self::assertGreaterThan($debitStart, $debitEnd);
        $walletMetadata = substr(
            $coursePurchases,
            $debitStart,
            $debitEnd - $debitStart
        );
        self::assertStringNotContainsString("'coupon_code'", $walletMetadata);
        self::assertStringNotContainsString("'notes' => 'Idempotency: '", $coursePurchases);
    }

    private function throwWithSensitiveArgument(string $payload): never
    {
        throw new \RuntimeException($payload);
    }
}
