<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\AiProviderUnavailableException;
use App\Services\AiFailurePolicy;
use PHPUnit\Framework\TestCase;

final class AiFailurePolicyTest extends TestCase
{
    public function test_unknown_provider_outcome_is_never_offered_as_a_blind_retry(): void
    {
        $failure = (new AiFailurePolicy())->describe('chat_provider_outcome_unknown');

        self::assertSame('unknown_outcome', $failure['category']);
        self::assertFalse($failure['can_retry']);
        self::assertSame(0, $failure['retry_after_seconds']);
    }

    public function test_provider_statuses_have_stable_public_classification(): void
    {
        $policy = new AiFailurePolicy();

        self::assertSame(
            'ai_rate_limited',
            $policy->providerCode(new AiProviderUnavailableException(true, providerStatus: 429))
        );
        self::assertSame(
            'ai_configuration_unavailable',
            $policy->providerCode(new AiProviderUnavailableException(false, providerStatus: 402))
        );
    }

    public function test_broken_request_identity_or_context_is_not_retried(): void
    {
        $policy = new AiFailurePolicy();

        self::assertFalse($policy->describe('chat_usage_identity_mismatch')['can_retry']);
        self::assertFalse($policy->describe('project_context_missing')['can_retry']);
    }
}
