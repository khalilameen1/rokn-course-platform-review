<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AiProviderUnavailableException;
use App\Services\OpenRouterService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class OpenRouterStreamingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'openrouter.api_key' => 'test-key',
            'openrouter.endpoint' => 'https://openrouter.test/chat',
            'openrouter.allowed_models' => ['test/model'],
            'openrouter.max_tokens' => 500,
            'openrouter.timeout_seconds' => 20,
        ]);
    }

    public function test_streaming_chat_returns_one_final_envelope_and_progressive_text(): void
    {
        $first = 'SQLSTATE يوضح فئة خطأ قاعدة البيانات '.str_repeat('أ', 60);
        $second = ' ثم الإجابة النهائية';
        Http::fake([
            'https://openrouter.test/chat' => Http::response(
                $this->event(['id' => 'gen-1', 'model' => 'test/model', 'choices' => [[
                    'delta' => ['content' => $first], 'finish_reason' => null,
                ]]])
                .$this->event(['id' => 'gen-1', 'model' => 'test/model', 'choices' => [[
                    'delta' => ['content' => $second], 'finish_reason' => 'stop',
                ]]])
                .$this->event(['usage' => [
                    'prompt_tokens' => 11,
                    'completion_tokens' => 22,
                    'total_tokens' => 33,
                    'cost' => 0.012,
                ]])
                ."data: [DONE]\n\n",
                200,
                ['Content-Type' => 'text/event-stream', 'X-Generation-Id' => 'gen-1']
            ),
        ]);
        $partials = [];

        $result = app(OpenRouterService::class)->chat(
            'test/model',
            [['role' => 'user', 'content' => 'السؤال']],
            .3,
            200,
            'request-1',
            null,
            function (string $partial) use (&$partials): void {
                $partials[] = $partial;
            }
        );

        self::assertSame($first.$second, $result['message']);
        self::assertSame('gen-1', $result['provider_request_id']);
        self::assertSame(33, $result['usage']['total_tokens']);
        self::assertSame($first.$second, $partials[array_key_last($partials)]);
        self::assertGreaterThanOrEqual(2, count($partials));
        Http::assertSent(static function (Request $request): bool {
            return $request['stream'] === true
                && $request['stream_options']['include_usage'] === true
                && is_string($request['user'])
                && strlen($request['user']) === 64;
        });
    }

    public function test_progress_checkpoint_failure_does_not_abort_the_paid_result(): void
    {
        Http::fake([
            'https://openrouter.test/chat' => Http::response(
                $this->event(['id' => 'gen-2', 'choices' => [[
                    'delta' => ['content' => str_repeat('ب', 60)],
                    'finish_reason' => 'stop',
                ]]])
                ."data: [DONE]\n\n",
                200,
                ['Content-Type' => 'text/event-stream']
            ),
        ]);

        $result = app(OpenRouterService::class)->chat(
            'test/model',
            [['role' => 'user', 'content' => 'السؤال']],
            .3,
            200,
            'request-2',
            null,
            static function (string $partial): never {
                throw new RuntimeException('checkpoint unavailable');
            }
        );

        self::assertSame(str_repeat('ب', 60), $result['message']);
    }

    public function test_interrupted_stream_checkpoints_the_last_visible_text_before_failing(): void
    {
        $visible = 'ابدأ من أول سطر في stack trace يخص كودك';
        Http::fake([
            'https://openrouter.test/chat' => Http::response(
                $this->event(['id' => 'gen-3', 'choices' => [[
                    'delta' => ['content' => $visible],
                    'finish_reason' => null,
                ]]]),
                200,
                ['Content-Type' => 'text/event-stream']
            ),
        ]);
        $partials = [];

        try {
            app(OpenRouterService::class)->chat(
                'test/model',
                [['role' => 'user', 'content' => 'السؤال']],
                .3,
                200,
                'request-3',
                null,
                function (string $partial) use (&$partials): void {
                    $partials[] = $partial;
                }
            );
            self::fail('The incomplete provider stream must remain terminal.');
        } catch (AiProviderUnavailableException $exception) {
            self::assertTrue($exception->outcomeUnknown);
        }

        self::assertSame([$visible], $partials);
    }

    /** @param array<string,mixed> $payload */
    private function event(array $payload): string
    {
        return 'data: '.json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )."\n\n";
    }
}
