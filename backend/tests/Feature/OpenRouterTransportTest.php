<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AiProviderUnavailableException;
use App\Services\OpenRouterService;
use App\Services\OpenRouterCurlFactory;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class OpenRouterTransportTest extends TestCase
{
    private ?Process $server = null;

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
        $started = hrtime(true);
        $partials = [];
        $landings = 0;
        $firstPartialAt = null;
        try {
            app(OpenRouterService::class)->chat(
                'test/model', [['role' => 'user', 'content' => 'Local question']], .3, 100,
                null,
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
