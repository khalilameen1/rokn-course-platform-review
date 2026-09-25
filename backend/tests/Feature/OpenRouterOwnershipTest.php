<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AiProviderUnavailableException;
use App\Services\AiEntitlementBudgetService;
use App\Services\AiUsageSettlementService;
use App\Services\OpenRouterRequestPolicy;
use App\Services\OpenRouterResponseDecoder;
use App\Services\OpenRouterService;
use App\Services\PaidAiCallExecutionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OpenRouterOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            OpenRouterService::class,
            AiEntitlementBudgetService::class,
            AiUsageSettlementService::class,
            PaidAiCallExecutionService::class,
        ] as $service) {
            $this->app->bind($service, static function () use ($service): never {
                throw new \LogicException('Request/response projection must not resolve ' . $service);
            });
        }
        Http::preventStrayRequests();
        Http::fake(static fn () => throw new \LogicException('Projection must not send HTTP.'));
        Cache::shouldReceive('has', 'put', 'forget')->never();
        config([
            'openrouter.api_key' => '',
            'openrouter.default_model' => 'test/default',
            'openrouter.project_model' => 'test/project',
            'openrouter.allowed_models' => ['test/default', 'test/project', 'test/fallback'],
            'openrouter.fallback_models' => [],
            'openrouter.reasoning_effort' => 'none',
            'openrouter.max_tokens' => 800,
            'openrouter.provider_data_collection' => 'allow',
            'openrouter.provider_sort' => 'latency',
            'openrouter.provider_zdr' => false,
            'openrouter.web_search_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
        } finally {
            parent::tearDown();
        }
    }

    public function test_selection_reads_current_policy_without_resolving_transport_or_requiring_credentials(): void
    {
        $policy = app(OpenRouterRequestPolicy::class);
        self::assertSame('test/default', $policy->configuredModel());
        self::assertSame('test/project', $policy->configuredModel('project_model'));

        config(['openrouter.allowed_models' => ['test/fallback'],
            'openrouter.fallback_models' => ['not/allowed', 'test/fallback']]);
        self::assertSame('test/fallback', $policy->configuredModel('project_model'));

        config(['openrouter.allowed_models' => []]);
        try {
            $policy->configuredModel();
            self::fail('Missing allowed selection must not silently send the configured model.');
        } catch (AiProviderUnavailableException $exception) {
            self::assertSame('model_not_allowed', $exception->providerCode);
            self::assertFalse($exception->outcomeUnknown);
            self::assertFalse($exception->retrySafe);
        }
    }

    public function test_request_contract_preserves_bounded_tools_identity_and_stream_usage_without_io(): void
    {
        config([
            'openrouter.fallback_models' => ['test/fallback', 'not/allowed', 'test/fallback'],
            'openrouter.provider_data_collection' => 'invalid',
            'openrouter.provider_sort' => 'invalid',
            'openrouter.provider_zdr' => true,
            'openrouter.pdf_parser_engine' => 'test-parser',
            'openrouter.web_search_max_results' => 100,
            'openrouter.web_search_max_total_results' => 0,
        ]);
        $messages = [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'Explain this document'],
            ['type' => 'file', 'file' => ['file_data' => 'data:application/pdf;base64,AA==']],
        ]]];
        $payload = app(OpenRouterRequestPolicy::class)->payload(
            'test/default', $messages, 9, 10000, ' learner-request-1 ', true, true
        );

        self::assertSame($messages, $payload['messages']);
        self::assertSame(['test/default', 'test/fallback'], $payload['models']);
        self::assertArrayNotHasKey('model', $payload);
        self::assertSame(800, $payload['max_tokens']);
        self::assertSame(1.2, $payload['temperature']);
        self::assertSame(hash('sha256', 'learner-request-1'), $payload['user']);
        self::assertSame(['require_parameters' => true, 'data_collection' => 'allow',
            'sort' => 'latency', 'zdr' => true], $payload['provider']);
        self::assertSame([['id' => 'file-parser', 'pdf' => ['engine' => 'test-parser']]], $payload['plugins']);
        self::assertSame([['type' => 'openrouter:web_search', 'parameters' => [
            'engine' => 'auto', 'max_results' => 5, 'max_total_results' => 1,
            'search_context_size' => 'low',
        ]]], $payload['tools']);
        self::assertTrue($payload['stream']);
        self::assertSame(['include_usage' => true], $payload['stream_options']);
        self::assertArrayNotHasKey('reasoning', $payload);
    }

    public function test_disabled_optional_features_are_omitted_and_inputs_are_not_rewritten(): void
    {
        config(['openrouter.web_search_enabled' => false]);
        $payload = app(OpenRouterRequestPolicy::class)->payload(
            'test/default', [['role' => 'user', 'content' => 'Question']], -1, 0, ' ', true
        );
        self::assertSame('test/default', $payload['model']);
        self::assertSame(80, $payload['max_tokens']);
        self::assertEquals(0, $payload['temperature']);
        foreach (['models', 'tools', 'plugins', 'stream', 'stream_options', 'user'] as $key) {
            self::assertArrayNotHasKey($key, $payload);
        }
    }

    public function test_unapproved_model_cannot_be_projected_into_a_provider_request(): void
    {
        try {
            app(OpenRouterRequestPolicy::class)->payload('not/allowed', [], .2, 100);
            self::fail('The payload boundary must validate its own model.');
        } catch (AiProviderUnavailableException $exception) {
            self::assertSame('model_not_allowed', $exception->providerCode);
            self::assertFalse($exception->outcomeUnknown);
        }
    }

    #[DataProvider('costEvidence')]
    public function test_decoder_preserves_missing_vs_zero_cost_without_settlement(
        array $usage, int|float $expectedCost, bool $reported
    ): void {
        $result = app(OpenRouterResponseDecoder::class)->decode([
            'choices' => [['message' => ['content' => 'Answer']]],
            'usage' => $usage,
        ], 'test/default');
        self::assertSame($expectedCost, $result['usage']['cost']);
        self::assertSame($reported, $result['usage']['cost_reported']);
        self::assertSame(0, $result['usage']['prompt_tokens']);
        self::assertSame(0, $result['usage']['completion_tokens']);
        self::assertSame(0, $result['usage']['total_tokens']);
    }

    public static function costEvidence(): array
    {
        return [
            'missing' => [[], 0, false],
            'null' => [['cost' => null], 0, false],
            'nonnumeric' => [['cost' => 'pending'], 0, false],
            'reported zero' => [['cost' => 0], 0, true],
            'reported decimal' => [['cost' => '0.0025'], .0025, true],
        ];
    }

    public function test_decoder_separates_visible_text_citations_and_file_annotations(): void
    {
        $citation = ['type' => 'url_citation', 'url' => 'https://example.test/source'];
        $file = ['type' => 'file', 'name' => 'learner.pdf'];
        $body = [
            'id' => 'body-id',
            'choices' => [['message' => [
                'content' => [
                    ['type' => 'reasoning', 'text' => 'Do not display this'],
                    ['type' => 'text', 'text' => ' SQLSTATE is a code '],
                    ['type' => 'output_text', 'text' => "Second\x00 line"],
                ],
                'annotations' => [$citation, $file],
            ]]],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 7, 'total_tokens' => 19, 'cost' => .003],
        ];
        $before = $body;
        $result = app(OpenRouterResponseDecoder::class)->decode($body, 'test/default', 'header-id', 'HIT');
        self::assertSame("SQLSTATE is a code\nSecond line", $result['message']);
        self::assertSame('body-id', $result['provider_request_id']);
        self::assertSame(['generation_id' => 'header-id', 'response_cache_status' => 'HIT'], $result['provider_transport']);
        self::assertSame([$citation], $result['response_annotations']);
        self::assertSame([$file], $result['file_annotations']);
        self::assertSame(19, $result['usage']['total_tokens']);
        self::assertSame($before, $body);
    }

    #[DataProvider('unusableBodies')]
    public function test_unusable_success_envelopes_remain_unknown_paid_outcomes(mixed $body): void
    {
        try {
            app(OpenRouterResponseDecoder::class)->decode($body, 'test/default');
            self::fail('Unusable output must not become a successful answer or safe paid retry.');
        } catch (AiProviderUnavailableException $exception) {
            self::assertTrue($exception->outcomeUnknown);
            self::assertFalse($exception->retrySafe);
        }
    }

    public static function unusableBodies(): array
    {
        $withContent = static fn (mixed $content): array => [
            ['choices' => [['message' => ['content' => $content]]]],
        ];
        return [
            'not json' => [null],
            'error envelope' => [['error' => ['message' => 'unavailable']]],
            'reasoning only' => $withContent([
                ['type' => 'reasoning', 'text' => 'hidden'],
            ]),
            'control characters only' => $withContent("\x01\x02"),
            'too long' => $withContent(str_repeat('x', 12001)),
        ];
    }
}
