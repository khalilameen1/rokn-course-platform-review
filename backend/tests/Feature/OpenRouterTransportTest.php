<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AiProviderUnavailableException;
use App\Services\OpenRouterService;
use App\Services\OpenRouterEventStream;
use App\Services\OpenRouterCurlFactory;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class OpenRouterTransportTest extends TestCase
{
    private ?Process $server = null;

    public static function geminiReasoningPayloads(): array
    {
        return [
            'legacy none' => ['none', 'low'],
            'unsupported minimal' => ['minimal', 'low'],
            'low' => ['low', 'low'],
            'medium' => ['medium', 'medium'],
            'high' => ['high', 'high'],
            'unsupported xhigh' => ['xhigh', 'high'],
            'unsupported max' => ['max', 'high'],
        ];
    }

    #[DataProvider('geminiReasoningPayloads')]
    public function test_gemini_chat_preserves_supported_reasoning_and_omits_sampling_without_implicit_project_fallback(
        string $effort, string $expectedEffort
    ): void {
        config([
            'openrouter.api_key' => 'local-test-only',
            'openrouter.endpoint' => 'https://openrouter.test/chat/completions',
            'openrouter.default_model' => 'google/gemini-3.8-flash',
            'openrouter.project_model' => 'anthropic/claude-sonnet-5',
            'openrouter.allowed_models' => ['google/gemini-3.8-flash', 'anthropic/claude-sonnet-5'],
            'openrouter.fallback_models' => [],
            'openrouter.reasoning_effort' => $effort,
            'openrouter.max_tokens' => 800,
        ]);
        Http::preventStrayRequests();
        Http::fake(['openrouter.test/*' => Http::response([
            'id' => 'gemini-payload-test', 'model' => 'google/gemini-3.8-flash',
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'المثال محتاج تعديل ترتيب التنفيذ']]],
            'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 800, 'total_tokens' => 1800, 'cost' => .00375],
        ])]);
        $provider = app(OpenRouterService::class);
        self::assertSame('google/gemini-3.8-flash', $provider->configuredModel());
        self::assertSame('anthropic/claude-sonnet-5', $provider->configuredModel('project_model'));
        $result = $provider->chat($provider->configuredModel(), [['role' => 'user', 'content' => 'اشرح المثال']], .35, 800);
        self::assertSame('المثال محتاج تعديل ترتيب التنفيذ', $result['message']);
        self::assertSame(.00375, $result['usage']['cost']);
        Http::assertSent(function ($request) use ($expectedEffort): bool {
            $payload = $request->data();
            self::assertSame('google/gemini-3.8-flash', $payload['model']);
            self::assertArrayNotHasKey('models', $payload);
            foreach (['temperature', 'top_p', 'top_k'] as $parameter) self::assertArrayNotHasKey($parameter, $payload);
            self::assertSame(['effort' => $expectedEffort, 'exclude' => true], $payload['reasoning']);
            self::assertSame(800, $payload['max_tokens']);
            return true;
        });
        Http::assertSentCount(1);
    }

    public function test_gemini_uses_another_model_only_when_explicitly_configured_as_fallback(): void
    {
        config([
            'openrouter.api_key' => 'local-test-only',
            'openrouter.endpoint' => 'https://openrouter.test/chat/completions',
            'openrouter.allowed_models' => ['google/gemini-3.8-flash', 'anthropic/claude-sonnet-5'],
            'openrouter.fallback_models' => ['anthropic/claude-sonnet-5'],
            'openrouter.reasoning_effort' => 'none',
        ]);
        Http::fake(['openrouter.test/*' => Http::response([
            'choices' => [['message' => ['content' => 'رد الاختبار']]], 'usage' => ['total_tokens' => 30, 'cost' => .001],
        ])]);
        app(OpenRouterService::class)->chat('google/gemini-3.8-flash', [['role' => 'user', 'content' => 'السؤال']], .35, 800);
        Http::assertSent(static fn ($request): bool =>
            $request['models'] === ['google/gemini-3.8-flash', 'anthropic/claude-sonnet-5']
            && $request['reasoning'] === ['effort' => 'low', 'exclude' => true]
        );
        Http::assertSentCount(1);
    }

    public static function sonnetReasoningPayloads(): array
    {
        return [
            'standalone disabled without unsupported temperature' => ['none', ['enabled' => false, 'exclude' => true], ['anthropic/claude-sonnet-5'], false],
            'disabled' => ['none', ['enabled' => false, 'exclude' => true], ['anthropic/claude-sonnet-5', 'openai/gpt-5.6-luna']],
            'minimal maps to supported low' => ['minimal', ['effort' => 'low', 'exclude' => true], ['anthropic/claude-sonnet-5', 'openai/gpt-5-mini', 'openai/gpt-5.6-luna']],
            'explicit low' => ['low', ['effort' => 'low', 'exclude' => true], ['anthropic/claude-sonnet-5', 'openai/gpt-5-mini', 'openai/gpt-5.6-luna']],
        ];
    }

    #[DataProvider('sonnetReasoningPayloads')]
    public function test_sonnet_five_payload_respects_advertised_parameters(
        string $effort, array $reasoning, array $models, bool $withFallbacks = true
    ): void {
        config([
            'openrouter.api_key' => 'local-test-only',
            'openrouter.endpoint' => 'https://openrouter.test/chat/completions',
            'openrouter.allowed_models' => ['anthropic/claude-sonnet-5', 'openai/gpt-5-mini', 'openai/gpt-5.6-luna'],
            'openrouter.fallback_models' => $withFallbacks ? ['openai/gpt-5-mini', 'openai/gpt-5.6-luna'] : [],
            'openrouter.reasoning_effort' => $effort,
            'openrouter.max_tokens' => 800,
        ]);
        Http::fake(['openrouter.test/*' => Http::response([
            'id' => 'candidate-payload-test',
            'model' => 'anthropic/claude-sonnet-5',
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => "أول فقرة\n\nثاني فقرة"]]],
            'usage' => ['total_tokens' => 40, 'cost' => .001],
        ])]);

        $result = app(OpenRouterService::class)->chat(
            'anthropic/claude-sonnet-5', [['role' => 'user', 'content' => 'اشرح المثال']], .3, 600
        );

        self::assertSame("أول فقرة\n\nثاني فقرة", $result['message']);
        Http::assertSent(function ($request) use ($reasoning, $models): bool {
            $payload = $request->data();
            self::assertArrayNotHasKey('temperature', $payload);
            self::assertSame($reasoning, $payload['reasoning']);
            self::assertSame($models, $payload['models'] ?? [$payload['model']]);
            self::assertSame(600, $payload['max_tokens']);
            self::assertTrue($payload['provider']['require_parameters']);
            return true;
        });
        Http::assertSentCount(1);
    }

    protected function tearDown(): void
    {
        $this->server?->stop(0);
        parent::tearDown();
    }

    public static function successfulStreams(): array
    {
        return [['normal'], ['done_keep_alive']];
    }

    #[DataProvider('successfulStreams')]
    public function test_small_frames_are_delivered_before_completion_and_land_once(string $scenario): void
    {
        $this->startServer($scenario);
        $started = hrtime(true);
        $partials = [];
        $landings = [];
        $result = app(OpenRouterService::class)->chat(
            'test/model', [['role' => 'user', 'content' => 'Local question']], .3, 100,
            null,
            function (array $result) use (&$landings): void { $landings[] = $result; },
            function (string $text) use (&$partials, $started): void {
                $partials[] = ['text' => $text, 'seconds' => (hrtime(true) - $started) / 1e9];
            }
        );
        $elapsed = (hrtime(true) - $started) / 1e9;

        self::assertSame('First small fragment and final answer', $result['message']);
        self::assertSame('local-generation', $result['provider_request_id']);
        self::assertSame(0.012, $result['usage']['cost']);
        self::assertCount(1, $landings);
        self::assertSame('First small fragment', $partials[0]['text']);
        self::assertLessThan($elapsed - .2, $partials[0]['seconds']);
        self::assertLessThan(3.0, $elapsed, 'DONE must not wait for the socket to close.');
        self::assertSame(1, substr_count($this->server->getOutput(), 'REQUEST'));
    }

    public static function deadlineScenarios(): array
    {
        return [['drip', true], ['slow_headers', false], ['silent_body', true], ['headers_then_silence', true]];
    }

    #[DataProvider('deadlineScenarios')]
    public function test_one_total_deadline_covers_headers_and_body(string $scenario, bool $hasPartial): void
    {
        $this->startServer($scenario);
        Log::spy();
        $started = hrtime(true);
        $partials = [];
        $landings = 0;
        $firstPartialAt = null;
        try {
            app(OpenRouterService::class)->chat(
                'test/model', [['role' => 'user', 'content' => 'Local question']], .3, 100,
                'report-request-123',
                function () use (&$landings): void { ++$landings; },
                function (string $text) use (&$partials, &$firstPartialAt, $started): void {
                    $firstPartialAt ??= (hrtime(true) - $started) / 1e9;
                    $partials[] = $text;
                }
            );
            self::fail('The configured total deadline must stop this stream.');
        } catch (AiProviderUnavailableException $exception) {
            self::assertTrue($exception->outcomeUnknown);
            self::assertFalse($exception->retrySafe);
        }
        $elapsed = (hrtime(true) - $started) / 1e9;
        self::assertGreaterThanOrEqual(4.5, $elapsed);
        self::assertLessThan(6.5, $elapsed);
        self::assertSame(0, $landings);
        self::assertSame($hasPartial ? ['First small fragment'] : [], $partials);
        if ($hasPartial) {
            self::assertLessThan($scenario === 'headers_then_silence' ? 3.0 : 1.0, $firstPartialAt);
        }
        self::assertSame(1, substr_count($this->server->getOutput(), 'REQUEST'));
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(static function (string $message, array $context) use ($hasPartial): bool {
                $keys = [
                    'request_id', 'model', 'stream', 'max_tokens', 'curl_errno',
                    'http_code', 'total_time', 'starttransfer_time', 'size_download',
                    'received_fragments', 'visible_chars', 'generation_id',
                ];

                return $message === 'OpenRouter generation transport failed.'
                    && array_keys($context) === $keys
                    && $context['request_id'] === 'report-request-123'
                    && $context['model'] === 'test/model'
                    && $context['stream'] === true
                    && $context['max_tokens'] === 100
                    && $context['curl_errno'] === 28
                    && $context['http_code'] === ($hasPartial ? 200 : 0)
                    && $context['total_time'] >= 4.5
                    && $context['starttransfer_time'] >= 0
                    && $context['size_download'] >= 0
                    && $context['received_fragments'] === $hasPartial
                    && $context['visible_chars'] === ($hasPartial ? mb_strlen('First small fragment') : 0)
                    && $context['generation_id'] === ($hasPartial ? 'local-generation' : null);
            });
    }

    public function test_interrupted_stream_keeps_partial_but_never_lands(): void
    {
        $this->startServer('interrupted');
        $partials = [];
        $landings = 0;
        try {
            app(OpenRouterService::class)->chat(
                'test/model', [['role' => 'user', 'content' => 'Local question']], .3, 100,
                null,
                function () use (&$landings): void { ++$landings; },
                function (string $text) use (&$partials): void { $partials[] = $text; }
            );
            self::fail('An incomplete stream cannot become an accepted answer.');
        } catch (AiProviderUnavailableException $exception) {
            self::assertTrue($exception->outcomeUnknown);
        }
        self::assertSame(['First small fragment'], $partials);
        self::assertSame(0, $landings);
    }

    public static function providerErrors(): array
    {
        return [
            ['http_error', false, '429', []],
            ['error_before_content', false, '429', []],
            ['error_after_content', true, '429', ['First small fragment']],
            ['malformed_after_content', true, null, ['First small fragment']],
        ];
    }

    #[DataProvider('providerErrors')]
    public function test_error_envelopes_keep_known_and_unknown_outcomes_distinct(
        string $scenario, bool $unknown, ?string $code, array $expectedPartials
    ): void {
        $this->startServer($scenario);
        $partials = [];
        $landings = 0;
        try {
            app(OpenRouterService::class)->chat(
                'test/model', [['role' => 'user', 'content' => 'Local question']], .3, 100,
                null,
                function () use (&$landings): void { ++$landings; },
                function (string $text) use (&$partials): void { $partials[] = $text; }
            );
            self::fail('A provider error must not land an answer.');
        } catch (AiProviderUnavailableException $exception) {
            self::assertSame($unknown, $exception->outcomeUnknown);
            self::assertSame(!$unknown, $exception->retrySafe);
            self::assertSame($code, $exception->providerCode);
        }
        self::assertSame(0, $landings);
        self::assertSame($expectedPartials, $partials);
    }

    public function test_json_fallback_still_returns_accounted_final_answer(): void
    {
        $this->startServer('json_fallback');
        $landings = [];
        $result = app(OpenRouterService::class)->chat(
            'test/model', [['role' => 'user', 'content' => 'Local question']], .3, 100,
            null,
            function (array $result) use (&$landings): void { $landings[] = $result; },
            static function (string $text): void { self::fail('JSON fallback has no incremental frames.'); }
        );
        self::assertSame('Buffered JSON answer', $result['message']);
        self::assertSame(0.01, $result['usage']['cost']);
        self::assertCount(1, $landings);
    }

    public static function openRejections(): array
    {
        return [
            ['rejection_headers_silence', false],
            ['rejection_json', false],
            ['rejection_wrong_type', false],
            ['rejection_raw_sse', false],
            ['rejection_proper_sse', false],
            ['rejection_after_partial', true],
        ];
    }

    #[DataProvider('openRejections')]
    public function test_rejection_does_not_wait_for_the_socket_to_close(string $scenario, bool $unknown): void
    {
        $this->startServer($scenario);
        $started = hrtime(true);
        $partials = [];
        $landings = 0;
        try {
            app(OpenRouterService::class)->chat(
                'test/model', [['role' => 'user', 'content' => 'Local question']], .3, 100,
                null,
                function () use (&$landings): void { ++$landings; },
                function (string $text) use (&$partials): void { $partials[] = $text; }
            );
            self::fail('A complete rejection must stop the transport.');
        } catch (AiProviderUnavailableException $exception) {
            self::assertSame($unknown, $exception->outcomeUnknown);
            self::assertFalse($exception->retrySafe);
            self::assertSame(400, $exception->providerStatus);
            self::assertSame('400', $exception->providerCode);
            self::assertSame(
                $scenario === 'rejection_headers_silence' ? [] : [['type' => 'local-file']],
                $exception->fileAnnotations
            );
        }
        self::assertLessThan(2.0, (hrtime(true) - $started) / 1e9);
        self::assertSame($unknown ? ['First small fragment'] : [], $partials);
        self::assertSame(0, $landings);
        self::assertSame(1, substr_count($this->server->getOutput(), 'REQUEST'));
    }

    public function test_json_detection_does_not_treat_answer_text_or_incomplete_data_as_rejection(): void
    {
        $decoder = new OpenRouterEventStream(static function (): void {}, 'test/model');
        foreach ([
            '{"error":{"code":400,"message":"incomplete"}',
            '{"message":"An error code 400 is a bad request"}',
            '{"error":"An explanation of error handling"}',
            '{"error":{"code":400}}',
            '{"choices":[{"message":{"content":"An error example"}}],"error":{"code":400,"message":"quoted example"}}',
        ] as $body) {
            $decoder->rejectJsonError($body);
        }
        self::assertFalse($decoder->completed());
        self::assertFalse($decoder->receivedFragments());
    }

    public function test_vendor_rewind_recovery_cannot_dispatch_a_second_generation(): void
    {
        $factory = new OpenRouterCurlFactory();
        $handler = new CurlHandler(['handle_factory' => $factory]);
        // Configure a handle but do not execute it or access the network.
        $handle = $factory->create(new Request('POST', 'http://127.0.0.1:1', [], '{}'), []);
        // Guzzle's finishError explicitly recognizes cURL error 65. Some PHP
        // cURL builds do not expose its symbolic constant.
        $handle->errno = 65;
        try {
            CurlFactory::finish($handler, $handle, $factory)->wait();
            self::fail('The internal cURL recovery path must not create a second handle.');
        } catch (AiProviderUnavailableException $exception) {
            self::assertSame('request_replay_blocked', $exception->providerCode);
            self::assertTrue($exception->outcomeUnknown);
            self::assertFalse($exception->retrySafe);
        }
    }

    public static function generationModes(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('generationModes')]
    public function test_redirect_does_not_replay_a_generation(bool $streaming): void
    {
        $this->startServer('redirect');
        try {
            app(OpenRouterService::class)->chat(
                'test/model', [['role' => 'user', 'content' => 'Local question']], .3, 100,
                null, null, $streaming ? static function (string $text): void {} : null
            );
            self::fail('A provider redirect is not a generation response.');
        } catch (AiProviderUnavailableException $exception) {
            self::assertSame(307, $exception->providerStatus);
            self::assertFalse($exception->outcomeUnknown);
        }
        self::assertSame(1, substr_count($this->server->getOutput(), 'REQUEST'));
    }

    private function startServer(string $scenario): void
    {
        self::assertTrue(extension_loaded('curl'), 'The production streaming transport requires ext-curl.');
        $this->server = new Process([PHP_BINARY, '-n', base_path('tests/Fixtures/openrouter_sse_server.php'), $scenario]);
        $this->server->setTimeout(12);
        $this->server->start();
        $ready = $this->server->waitUntil(static fn (string $type, string $output): bool =>
            $type === Process::OUT && str_contains($output, "\n")
        );
        self::assertTrue($ready, $this->server->getErrorOutput());
        $address = trim($this->server->getOutput());
        self::assertMatchesRegularExpression('/^127\.0\.0\.1:\d+$/', $address);
        config([
            'openrouter.api_key' => 'local-only-not-a-real-key',
            'openrouter.endpoint' => 'http://'.$address,
            'openrouter.allowed_models' => ['test/model'],
            'openrouter.timeout_seconds' => 5,
            'openrouter.connect_timeout_seconds' => 1,
            'openrouter.max_tokens' => 200,
        ]);
    }
}
