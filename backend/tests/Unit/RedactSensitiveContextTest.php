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
        $handler = new TestHandler;
        $logger = new Logger('privacy', [$handler]);
        (new RedactSensitiveContext)($logger);

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
        $handler = new TestHandler;
        $logger = new Logger('privacy', [$handler]);
        (new RedactSensitiveContext)($logger);

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
        $handler = new TestHandler;
        $logger = new Logger('privacy', [$handler]);
        (new RedactSensitiveContext)($logger);

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
        foreach (['stack', 'single', 'daily', 'slack', 'papertrail', 'stderr', 'syslog', 'errorlog'] as $channel) {
            self::assertContains(
                RedactSensitiveContext::class,
                config("logging.channels.{$channel}.tap", []),
                "{$channel} must redact sensitive context"
            );
        }
    }

    // Redeemable codes stay out of free-form financial fields:
    // course codes - CourseCodeRedemptionOwnershipTest::test_one_transaction_creates_a_zero_value_receipt_without_charging_the_wallet;
    // coupons - CourseCheckoutServiceTest::test_coupon_purchase_keeps_the_redeemable_code_out_of_wallet_metadata_and_notes.

    private function throwWithSensitiveArgument(string $payload): never
    {
        throw new \RuntimeException($payload);
    }
}
