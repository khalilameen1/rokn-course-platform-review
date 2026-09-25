<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AiProviderUnavailableException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class OpenRouterService
{
    public const CIRCUIT_KEY = 'openrouter:circuit-open';

    public function __construct(
        private readonly OpenRouterRequestPolicy $requests,
        private readonly OpenRouterResponseDecoder $responses
    ) {
    }

    public function chat(
        string $model,
        array $messages,
        float $temperature,
        int $maxTokens,
        ?string $requestIdentity = null,
        ?callable $landImmediately = null,
        ?callable $onPartial = null,
        bool $allowWebSearch = false
    ): array {
        $apiKey = (string) config('openrouter.api_key');
        if ($apiKey === '' || $model === '') {
            throw new AiProviderUnavailableException(
                false,
                'AI service is not configured.',
                providerCode: 'not_configured'
            );
        }

        $payload = $this->requests->payload(
            $model, $messages, $temperature, $maxTokens,
            $requestIdentity, $allowWebSearch, $onPartial !== null
        );

        if ($this->circuitIsOpen()) {
            throw new AiProviderUnavailableException(
                false,
                providerCode: 'configuration_circuit_open'
            );
        }

        if (!extension_loaded('curl')) {
            throw new AiProviderUnavailableException(
                false,
                'AI generation requires the cURL extension.',
                providerCode: 'transport_not_configured'
            );
        }

        $eventStream = $onPartial !== null ? new OpenRouterEventStream($onPartial, $model) : null;
        $transportStats = [];
        try {
            $request = Http::withToken($apiKey)
                ->acceptJson()
                // A 307/308 redirect must not replay a billable generation.
                ->withOptions([
                    'allow_redirects' => false,
                    'on_stats' => static function (TransferStats $stats) use (&$transportStats): void {
                        $handler = $stats->getHandlerStats();
                        $handlerError = $stats->getHandlerErrorData();
                        $transportStats = [
                            'curl_errno' => is_numeric($handlerError)
                                ? max(0, (int) $handlerError)
                                : 0,
                            'http_code' => max(0, (int) ($handler['http_code'] ?? 0)),
                            'total_time' => max(0, (float) ($handler['total_time'] ?? 0)),
                            'starttransfer_time' => max(0, (float) ($handler['starttransfer_time'] ?? 0)),
                            'size_download' => max(0, (float) ($handler['size_download'] ?? 0)),
                        ];
                    },
                ])
                ->withHeaders([
                    'HTTP-Referer' => (string) config('app.url'),
                    'X-Title' => (string) config('app.name', 'Rokn'),
                    'X-OpenRouter-Cache' => 'true',
                    'X-OpenRouter-Cache-TTL' => (string) max(
                        1,
                        min(86400, (int) config(
                            'openrouter.response_recovery_cache_ttl_seconds',
                            900
                        ))
                    ),
                ])
                ->connectTimeout(max(1, (int) config('openrouter.connect_timeout_seconds', 5)))
                // Keep the network budget below the worker timeout, leaving
                // time to land and settle the result after the response closes.
                ->timeout(max(5, min(
                    50,
                    (int) config('openrouter.timeout_seconds', 45)
                )));
            // cURL owns the whole connect/headers/body deadline. When streaming,
            // its sink delivers small SSE fragments without a blocking body read.
            $request->setHandler($this->generationHandler($eventStream));
            $response = $request->post((string) config('openrouter.endpoint'), $payload);
        } catch (ConnectionException $exception) {
            $this->logTransportFailure(
                $requestIdentity,
                $model,
                $payload,
                $eventStream,
                $transportStats
            );
            // A timeout may happen after the provider accepted and billed the
            // request. Do not issue a blind second paid call.
            throw new AiProviderUnavailableException(
                false,
                previous: $exception,
                outcomeUnknown: true
            );
        } catch (AiProviderUnavailableException $exception) {
            $this->logTransportFailure(
                $requestIdentity,
                $model,
                $payload,
                $eventStream,
                $transportStats
            );
            throw $exception;
        } finally {
            $eventStream?->flush();
        }

        if (!$response->successful()) {
            $failureAnnotations = $response->json('error.metadata.file_annotations');
            if (!is_array($failureAnnotations)) $failureAnnotations = [];
            $providerCode = trim((string) $response->json('error.code'));
            $providerCode = $providerCode !== ''
                ? substr($providerCode, 0, 80)
                : (string) $response->status();
            Log::warning('OpenRouter rejected a generation request.', [
                'status' => $response->status(),
                'provider_code' => $providerCode,
                'model' => $model,
            ]);
            if ($response->status() === 402) {
                $this->openCircuit(
                    'billing',
                    max(60, (int) config('openrouter.billing_circuit_open_seconds', 900))
                );
            } elseif (in_array($response->status(), [401, 403], true)) {
                $this->openCircuit(
                    'authentication',
                    max(60, (int) config('openrouter.billing_circuit_open_seconds', 900))
                );
            }
            throw new AiProviderUnavailableException(
                in_array($response->status(), [408, 429], true) || $response->serverError(),
                fileAnnotations: $failureAnnotations,
                // A complete non-2xx response is a known rejected request.
                // Only a connection/stream interruption after acceptance has
                // an unknown billable outcome and must not be replayed.
                outcomeUnknown: false,
                providerStatus: $response->status(),
                providerCode: $providerCode
            );
        }

        $isEventStream = str_contains(
            strtolower((string) $response->header('Content-Type')),
            'text/event-stream'
        );
        if ($eventStream !== null && $isEventStream) {
            // Buffered responses (including Laravel HTTP fakes) use the same
            // decoder. Live cURL responses have already delivered each frame.
            try {
                if (!$eventStream->receivedFragments()) {
                    $eventStream->append($response->body());
                }
                $body = $eventStream->finish();
            } catch (AiProviderUnavailableException $exception) {
                $this->logTransportFailure(
                    $requestIdentity,
                    $model,
                    $payload,
                    $eventStream,
                    $transportStats
                );
                throw $exception;
            } finally {
                $eventStream->flush();
            }
        } else {
            $body = $response->json();
        }
        $result = $this->responses->decode(
            $body, $model,
            $response->header('X-Generation-Id'),
            $response->header('X-OpenRouter-Cache-Status')
        );
        $this->recordSuccess();

        // Land at the provider boundary before returning through formatting,
        // settlement and learner-facing layers. The caller repeats the same
        // idempotent landing as a defensive check.
        if ($landImmediately !== null) {
            $landImmediately($result);
        }

        return $result;
    }

    private function generationHandler(?OpenRouterEventStream $stream): callable
    {
        $handler = new CurlHandler(['handle_factory' => new OpenRouterCurlFactory()]);
        if ($stream === null) {
            return $handler;
        }

        return static function (RequestInterface $request, array $options) use ($stream, $handler): PromiseInterface {
            $body = Utils::streamFor();
            $headers = null;
            $isEventStream = false;
            $decoderFailure = null;
            $options['on_headers'] = static function (ResponseInterface $response) use ($stream, &$headers, &$isEventStream): void {
                $headers = $response;
                $stream->captureGenerationId($response->getHeaderLine('X-Generation-Id'));
                if ($response->getStatusCode() >= 400) {
                    // Rejection is already known. Waiting for an optional error
                    // body can turn a silent keep-alive into an unknown timeout.
                    throw new \RuntimeException('Provider rejected the request headers.');
                }
                $isEventStream = $response->getStatusCode() >= 200
                    && $response->getStatusCode() < 300
                    && str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/event-stream');
            };
            $options['sink'] = FnStream::decorate($body, [
                'write' => static function (string $chunk) use ($stream, $body, &$isEventStream, &$decoderFailure): int {
                    try {
                        if (!$isEventStream) {
                            $written = $body->write($chunk);
                            $stream->rejectJsonError((string) $body);
                            return $written;
                        }
                        $stream->append($chunk);
                    } catch (Throwable $exception) {
                        $decoderFailure = $exception;
                        return 0;
                    }
                    // A terminal SSE marker is enough. Do not wait for a
                    // provider to close a keep-alive connection after DONE.
                    return $stream->completed() ? 0 : strlen($chunk);
                },
            ]);

            return $handler($request, $options)->then(
                null,
                static function (Throwable $exception) use ($stream, &$headers, &$decoderFailure): ResponseInterface {
                    if ($decoderFailure !== null) {
                        throw $decoderFailure;
                    }
                    if ($headers !== null && $headers->getStatusCode() >= 400) {
                        return $headers;
                    }
                    if ($stream->completed() && $headers !== null) {
                        return $headers;
                    }
                    throw new AiProviderUnavailableException(
                        false,
                        'AI provider stream was interrupted.',
                        previous: $exception,
                        outcomeUnknown: true
                    );
                }
            );
        };
    }

    /** @param array<string,mixed> $payload @param array<string,int|float> $transportStats */
    private function logTransportFailure(
        ?string $requestIdentity,
        string $model,
        array $payload,
        ?OpenRouterEventStream $eventStream,
        array $transportStats
    ): void {
        $requestId = preg_replace(
            '/[^A-Za-z0-9._:-]/',
            '_',
            trim((string) $requestIdentity)
        );
        $stream = $eventStream?->diagnostics() ?? [
            'received_fragments' => false,
            'visible_chars' => 0,
            'generation_id' => null,
        ];
        Log::warning('OpenRouter generation transport failed.', [
            'request_id' => $requestId !== '' ? substr((string) $requestId, 0, 128) : null,
            'model' => substr($model, 0, 128),
            'stream' => $eventStream !== null,
            'max_tokens' => max(0, (int) ($payload['max_tokens'] ?? 0)),
            'curl_errno' => max(0, (int) ($transportStats['curl_errno'] ?? 0)),
            'http_code' => max(0, (int) ($transportStats['http_code'] ?? 0)),
            'total_time' => max(0, (float) ($transportStats['total_time'] ?? 0)),
            'starttransfer_time' => max(0, (float) ($transportStats['starttransfer_time'] ?? 0)),
            'size_download' => max(0, (float) ($transportStats['size_download'] ?? 0)),
            'received_fragments' => (bool) $stream['received_fragments'],
            'visible_chars' => max(0, (int) $stream['visible_chars']),
            'generation_id' => $stream['generation_id'],
        ]);
    }

    private function circuitIsOpen(): bool
    {
        try {
            return Cache::has(self::CIRCUIT_KEY);
        } catch (\Throwable) {
            // Cache failure is already visible in operational health. It must
            // not turn an otherwise usable AI provider into a false outage.
            return false;
        }
    }

    private function openCircuit(string $reason, int $seconds): void
    {
        try {
            Cache::put(
                self::CIRCUIT_KEY,
                ['reason' => $reason, 'opened_at' => now()->toIso8601String()],
                now()->addSeconds(max(60, $seconds))
            );
        } catch (\Throwable $exception) {
            Log::warning('OpenRouter circuit could not be opened.', [
                'reason' => $reason,
                'exception' => $exception::class,
            ]);
        }
    }

    private function recordSuccess(): void
    {
        try {
            Cache::forget(self::CIRCUIT_KEY);
        } catch (\Throwable) {
            // Successful student output is never failed by monitoring state.
        }
    }
}
