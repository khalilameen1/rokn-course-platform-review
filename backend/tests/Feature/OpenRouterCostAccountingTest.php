<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AiProviderUnavailableException;
use App\Services\OpenRouterService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OpenRouterCostAccountingTest extends TestCase
{
    public function test_provider_error_pages_and_tool_only_envelopes_are_not_delivered_as_answers(): void
    {
        $bodies = [
            '<html><body>upstream unavailable</body></html>',
            ['error' => ['message' => 'provider error']],
            ['choices' => [['message' => ['tool_calls' => [['id' => 'tool-1']]]]]],
        ];
        $sequence = Http::sequence();
        foreach ($bodies as $body) {
            $sequence->push($body);
        }
        Http::fake(['openrouter.test/*' => $sequence]);
        foreach ($bodies as $body) {
            $landed = false;
            try {
                app(OpenRouterService::class)->chat(
                    'test/model',
                    [['role' => 'user', 'content' => 'اشرح المثال']],
                    0.2,
                    100,
                    landImmediately: function () use (&$landed): void {
                        $landed = true;
                    }
                );
                self::fail('An error envelope is not an educational answer.');
            } catch (AiProviderUnavailableException $exception) {
                self::assertTrue($exception->outcomeUnknown);
                self::assertFalse($landed);
            }
        }
    }

    public function test_technical_lesson_answers_are_delivered_and_landed_not_mistaken_for_provider_errors(): void
    {
        $answers = [
            'SQLSTATE يوضح فئة الخطأ الذي رجع من قاعدة البيانات',
            'ابدأ من أول سطر في stack trace يخص كودك',
            'uncaught exception يعني أن الخطأ وصل دون معالجة',
            'provider error هنا اسم حقل في الرد وليس نص الإجابة',
            'tool calls هي طلبات النموذج لتنفيذ أدوات خارجية',
            '<html lang="ar"><body>مرحبا</body></html>',
        ];
        $sequence = Http::sequence();
        foreach ($answers as $answer) {
            $sequence->push([
                'id' => 'generation-technical-answer',
                'choices' => [['finish_reason' => 'stop', 'message' => ['content' => $answer]]],
                'usage' => ['total_tokens' => 30, 'cost' => 0.001],
            ]);
        }
        Http::fake(['openrouter.test/*' => $sequence]);
        foreach ($answers as $answer) {
            $landed = [];
            $result = app(OpenRouterService::class)->chat(
                'test/model',
                [['role' => 'user', 'content' => 'اشرح المثال']],
                0.2,
                100,
                landImmediately: function (array $result) use (&$landed): void {
                    $landed[] = $result;
                }
            );
            self::assertSame($answer, $result['message']);
            self::assertSame([$result], $landed);
            self::assertTrue($result['usage']['cost_reported']);
            self::assertSame(0.001, $result['usage']['cost']);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('openrouter:circuit-open');
        config()->set('openrouter.api_key', 'test-key');
        config()->set('openrouter.allowed_models', ['test/model']);
        config()->set('openrouter.endpoint', 'https://openrouter.test/chat');
        config()->set('openrouter.timeout_seconds', 5);
        config()->set('openrouter.max_tokens', 500);
        config()->set('openrouter.reasoning_effort', 'none');
        config()->set('openrouter.provider_data_collection', 'allow');
        config()->set('openrouter.provider_zdr', false);
    }

    public function test_zero_provider_cost_is_preserved_as_a_real_cost_fact(): void
    {
        Http::fake(['openrouter.test/*' => Http::response([
            'id' => 'generation-free',
            'choices' => [['message' => ['content' => 'answer']]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
                'total_tokens' => 15,
                'cost' => 0,
            ],
        ])]);

        $result = app(OpenRouterService::class)->chat(
            'test/model',
            [['role' => 'user', 'content' => 'question']],
            0.2,
            100
        );

        self::assertEquals(0.0, $result['usage']['cost']);
        self::assertTrue($result['usage']['cost_reported']);
    }

    public function test_missing_provider_cost_is_marked_for_reservation_fallback(): void
    {
        Http::fake(['openrouter.test/*' => Http::response([
            'id' => 'generation-without-cost',
            'choices' => [['message' => ['content' => 'answer']]],
            'usage' => ['total_tokens' => 15],
        ])]);

        $result = app(OpenRouterService::class)->chat(
            'test/model',
            [['role' => 'user', 'content' => 'question']],
            0.2,
            100
        );

        self::assertFalse($result['usage']['cost_reported']);
    }

    public function test_course_chat_reserves_the_budget_for_visible_output(): void
    {
        Http::fake(['openrouter.test/*' => Http::response([
            'id' => 'generation-visible-output',
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['content' => 'answer'],
            ]],
            'usage' => ['total_tokens' => 15, 'cost' => 0.001],
        ])]);

        app(OpenRouterService::class)->chat(
            'test/model',
            [['role' => 'user', 'content' => 'question']],
            0.2,
            100
        );

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://openrouter.test/chat'
                && ($payload['max_tokens'] ?? null) === 100
                && !array_key_exists('max_completion_tokens', $payload)
                && ($payload['temperature'] ?? null) === 0.2
                && ($payload['provider']['require_parameters'] ?? null) === true
                && ($payload['provider']['data_collection'] ?? null) === 'allow'
                && !array_key_exists('zdr', $payload['provider'])
                && !array_key_exists('reasoning', $payload);
        });
    }

    public function test_zero_retention_only_restricts_routing_when_explicitly_enabled(): void
    {
        config()->set('openrouter.provider_data_collection', 'deny');
        config()->set('openrouter.provider_zdr', true);
        Http::fake(['openrouter.test/*' => Http::response([
            'id' => 'generation-zdr',
            'choices' => [['message' => ['content' => 'answer']]],
            'usage' => ['total_tokens' => 15, 'cost' => 0.001],
        ])]);

        app(OpenRouterService::class)->chat(
            'test/model',
            [['role' => 'user', 'content' => 'question']],
            0.2,
            100
        );

        Http::assertSent(static fn ($request): bool =>
            ($request['provider']['data_collection'] ?? null) === 'deny'
            && ($request['provider']['zdr'] ?? null) === true
        );
    }

    public function test_gpt_five_request_keeps_the_production_fallback_on_the_shared_parameter_contract(): void
    {
        config()->set('openrouter.allowed_models', [
            'openai/gpt-5-mini',
            'anthropic/claude-sonnet-5',
        ]);
        config()->set('openrouter.fallback_models', [
            'anthropic/claude-sonnet-5',
        ]);
        Http::fake(['openrouter.test/*' => Http::response([
            'id' => 'generation-gpt-five',
            'choices' => [['message' => ['content' => 'answer']]],
            'usage' => ['total_tokens' => 15, 'cost' => 0.001],
        ])]);

        app(OpenRouterService::class)->chat(
            'openai/gpt-5-mini',
            [['role' => 'user', 'content' => 'question']],
            0.2,
            100
        );

        Http::assertSent(static function ($request): bool {
            $payload = $request->data();

            return ($payload['models'] ?? null) === [
                'openai/gpt-5-mini',
                'anthropic/claude-sonnet-5',
            ]
                && ($payload['max_tokens'] ?? null) === 100
                && !array_key_exists('max_completion_tokens', $payload)
                && ($payload['provider']['require_parameters'] ?? null) === true
                && !array_key_exists('temperature', $payload)
                && ($payload['reasoning']['effort'] ?? null) === 'minimal';
        });
    }

    public function test_realtime_gpt_five_six_disables_default_reasoning_and_drops_incompatible_fallback(): void
    {
        config()->set('openrouter.allowed_models', [
            'openai/gpt-5.6-luna',
            'openai/gpt-5.6-terra',
            'anthropic/claude-sonnet-5',
        ]);
        config()->set('openrouter.fallback_models', [
            'anthropic/claude-sonnet-5',
            'openai/gpt-5.6-terra',
        ]);
        Http::fake(['openrouter.test/*' => Http::response([
            'id' => 'generation-gpt-five-six',
            'choices' => [['message' => ['content' => 'answer']]],
            'usage' => ['total_tokens' => 15, 'cost' => 0.001],
        ])]);

        app(OpenRouterService::class)->chat(
            'openai/gpt-5.6-luna',
            [['role' => 'user', 'content' => 'question']],
            0.2,
            260
        );

        Http::assertSent(static function ($request): bool {
            $payload = $request->data();

            return ($payload['models'] ?? null) === [
                'openai/gpt-5.6-luna',
                'openai/gpt-5.6-terra',
            ]
                && ($payload['reasoning']['effort'] ?? null) === 'none'
                && ($payload['reasoning']['exclude'] ?? null) === true
                && !array_key_exists('temperature', $payload);
        });
    }

    public function test_provider_rejection_keeps_safe_diagnostics(): void
    {
        Http::fake(['openrouter.test/*' => Http::response([
            'error' => ['code' => 'unsupported_parameter'],
        ], 400)]);

        try {
            app(OpenRouterService::class)->chat(
                'test/model',
                [['role' => 'user', 'content' => 'question']],
                0.2,
                100
            );
            self::fail('The provider rejection should be surfaced.');
        } catch (\App\Exceptions\AiProviderUnavailableException $exception) {
            self::assertSame(400, $exception->providerStatus);
            self::assertSame('unsupported_parameter', $exception->providerCode);
            self::assertFalse($exception->outcomeUnknown);
        }
    }

    public function test_text_parts_are_joined_without_exposing_reasoning_parts(): void
    {
        Http::fake(['openrouter.test/*' => Http::response([
            'id' => 'generation-content-parts',
            'choices' => [['message' => ['content' => [
                ['type' => 'reasoning', 'text' => 'private chain of thought'],
                ['type' => 'text', 'text' => 'السطر الأول'],
                ['type' => 'output_text', 'text' => 'السطر الثاني'],
            ]]]],
            'usage' => ['total_tokens' => 15, 'cost' => 0.001],
        ])]);

        $result = app(OpenRouterService::class)->chat(
            'test/model',
            [['role' => 'user', 'content' => 'question']],
            0.2,
            100
        );

        self::assertSame("السطر الأول\nالسطر الثاني", $result['message']);
        self::assertStringNotContainsString('private chain of thought', $result['message']);
    }

    public function test_course_chat_uses_bounded_web_search_and_provider_fallbacks(): void
    {
        config()->set('openrouter.allowed_models', ['test/model', 'test/fallback']);
        config()->set('openrouter.fallback_models', ['test/fallback']);
        config()->set('openrouter.provider_sort', 'latency');
        config()->set('openrouter.web_search_enabled', true);
        config()->set('openrouter.web_search_max_results', 99);
        config()->set('openrouter.web_search_max_total_results', 99);
        Http::fake(['openrouter.test/*' => Http::response([
            'id' => 'generation-with-search',
            'choices' => [['message' => [
                'content' => 'answer',
                'annotations' => [
                    ['type' => 'url_citation', 'url' => 'https://example.test/source'],
                    ['type' => 'file_citation', 'index' => 0],
                ],
            ]]],
            'usage' => ['total_tokens' => 15, 'cost' => 0.001],
        ])]);

        $result = app(OpenRouterService::class)->chat(
            'test/model',
            [['role' => 'user', 'content' => 'question']],
            0.2,
            100,
            null,
            null,
            null,
            true
        );

        self::assertSame('url_citation', $result['response_annotations'][0]['type']);
        self::assertSame('file_citation', $result['file_annotations'][0]['type']);
        Http::assertSent(static function ($request): bool {
            $payload = $request->data();

            return ($payload['models'] ?? null) === ['test/model', 'test/fallback']
                && !array_key_exists('model', $payload)
                && ($payload['provider']['sort'] ?? null) === 'latency'
                && ($payload['tools'][0]['type'] ?? null) === 'openrouter:web_search'
                && ($payload['tools'][0]['parameters']['max_results'] ?? null) === 5
                && ($payload['tools'][0]['parameters']['max_total_results'] ?? null) === 8;
        });
    }
}
