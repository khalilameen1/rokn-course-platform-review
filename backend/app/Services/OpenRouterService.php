<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AiProviderUnavailableException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
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

    public function configuredModel(string $preferredKey = 'default_model'): string
    {
        $allowed = array_values(array_filter((array) config('openrouter.allowed_models', [])));
        $candidates = array_values(array_unique(array_filter([
            trim((string) config("openrouter.{$preferredKey}")),
            trim((string) config('openrouter.default_model')),
            ...array_map('trim', (array) config('openrouter.fallback_models', [])),
        ])));
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $allowed, true)) {
                return $candidate;
            }
        }

        throw new AiProviderUnavailableException(
            false,
            'No configured AI model is permitted by the production allowlist.',
            providerCode: 'model_not_allowed'
        );
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

        $allowed = array_values(array_filter(config('openrouter.allowed_models', [])));
        if ($allowed === [] || !in_array($model, $allowed, true)) {
            throw new AiProviderUnavailableException(
                false,
                'AI model is not in the production allowlist.',
                providerCode: 'model_not_allowed'
            );
        }

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

        $reasoningEffort = $this->reasoningEffort($model);
        $models = array_values(array_unique(array_filter([
            $model,
            ...array_values(array_filter(
                (array) config('openrouter.fallback_models', []),
                static fn (mixed $fallback): bool => is_string($fallback)
                    && in_array($fallback, $allowed, true)
            )),
        ])));
        if ($reasoningEffort === 'none') {
            // A model fallback receives the same request body as the primary.
            // Do not advertise a fallback whose reasoning contract rejects
            // the explicit no-reasoning mode used by the real-time chat.
            $models = array_values(array_filter(
                $models,
                fn (string $candidate): bool => $this->supportsNoReasoning($candidate)
            ));
        }
        $payload = [
            'messages' => $messages,
            // Use OpenRouter's shared output ceiling so strict parameter
            // routing retains the configured provider fallbacks.
            'max_tokens' => max(
                80,
                min((int) config('openrouter.max_tokens', 800), $maxTokens)
            ),
            'provider' => [
                'require_parameters' => true,
                'data_collection' => in_array(
                    config('openrouter.provider_data_collection'),
                    ['allow', 'deny'],
                    true
                ) ? config('openrouter.provider_data_collection') : 'allow',
                'sort' => in_array(
                    config('openrouter.provider_sort'),
                    ['latency', 'throughput', 'price'],
                    true
                ) ? config('openrouter.provider_sort') : 'latency',
            ],
        ];
        if ((bool) config('openrouter.provider_zdr', false)) {
            $payload['provider']['zdr'] = true;
        }
        if (count($models) > 1) {
            // OpenRouter owns failover inside one billable request. Retrying a
            // second model from our queue after an uncertain response could
            // charge twice and deliver two different answers.
            $payload['models'] = $models;
        } else {
            $payload['model'] = $model;
        }
        if ($reasoningEffort !== null) {
            $payload['reasoning'] = [
                'effort' => $reasoningEffort,
                'exclude' => true,
            ];
        }
        // GPT-5 endpoints do not advertise temperature support. Sending it
        // anyway can make an otherwise healthy provider reject the request
        // before generation starts. Keep sampling control for models that
        // support it instead of weakening every model to the same payload.
        if (collect($models)->every(fn (string $candidate): bool =>
            $this->supportsTemperature($candidate)
        )) {
            $payload['temperature'] = max(0, min(1.2, $temperature));
        }
        if (trim((string) $requestIdentity) !== '') {
            // The stable external-user value is part of the request body, so
            // an identical recovery attempt cannot accidentally address a
            // different logical learner request.
            $payload['user'] = substr(
                hash('sha256', trim((string) $requestIdentity)),
                0,
                64
            );
        }
        if ($this->containsPdf($messages)) {
            $payload['plugins'] = [[
                'id' => 'file-parser',
                'pdf' => ['engine' => (string) config('openrouter.pdf_parser_engine', 'cloudflare-ai')],
            ]];
        }
        if ($allowWebSearch && (bool) config('openrouter.web_search_enabled', true)) {
            // The model decides whether current information is required. A
            // bounded server tool avoids paying for a search on ordinary
            // teaching questions while keeping time-sensitive answers honest.
            $payload['tools'] = [[
                'type' => 'openrouter:web_search',
                'parameters' => [
                    'engine' => 'auto',
                    'max_results' => max(1, min(
                        5,
                        (int) config('openrouter.web_search_max_results', 3)
                    )),
                    'max_total_results' => max(1, min(
                        8,
                        (int) config('openrouter.web_search_max_total_results', 5)
                    )),
                    'search_context_size' => 'low',
                ],
            ]];
        }
        if ($onPartial !== null) {
            $payload['stream'] = true;
            $payload['stream_options'] = ['include_usage' => true];
        }

        $eventStream = $onPartial !== null ? new OpenRouterEventStream($onPartial, $model) : null;
        try {
            $request = Http::withToken($apiKey)
                ->acceptJson()
                // A 307/308 redirect must not replay a billable generation.
                ->withOptions(['allow_redirects' => false])
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
            // A timeout may happen after the provider accepted and billed the
            // request. Do not issue a blind second paid call.
            throw new AiProviderUnavailableException(
                false,
                previous: $exception,
                outcomeUnknown: true
            );
        } finally {
            $eventStream?->flush();
        }

        if (!$response->successful()) {
            $failureAnnotations = $response->json('error.metadata.file_annotations');
            if (!is_array($failureAnnotations)) $failureAnnotations = [];
            $providerCode = trim((string) $response->json('error.code'));
            $providerCode = $providerCode !== ''
                ? substr($providerCode, 0, 80)
                : null;
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
            } finally {
                $eventStream->flush();
            }
        } else {
            $body = $response->json();
        }
        $content = $this->learnerVisibleContent(
            data_get($body, 'choices.0.message.content')
        );
        if ($content === '') {
            Log::warning('OpenRouter returned no learner-visible answer.', [
                'provider_request_id' => data_get($body, 'id'),
                'model' => data_get($body, 'model', $model),
                'finish_reason' => data_get($body, 'choices.0.finish_reason'),
                'native_finish_reason' => data_get($body, 'choices.0.native_finish_reason'),
                'completion_tokens' => max(
                    0,
                    (int) data_get($body, 'usage.completion_tokens', 0)
                ),
                'reasoning_returned' => filled(
                    data_get($body, 'choices.0.message.reasoning')
                ) || filled(data_get($body, 'choices.0.message.reasoning_details')),
            ]);
            throw new AiProviderUnavailableException(
                false,
                'AI provider returned an empty response.',
                outcomeUnknown: true
            );
        }
        // Provider failures belong to the HTTP/error envelope. Technical terms
        // and code inside message.content can be exactly what the lesson teaches.
        $content = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $content));
        if ($content === '' || mb_strlen($content) > 12000) {
            throw new AiProviderUnavailableException(
                false,
                'AI provider returned an unusable response.',
                outcomeUnknown: true
            );
        }

        $this->recordSuccess();

        $providerCost = data_get($body, 'usage.cost');
        $annotations = data_get($body, 'choices.0.message.annotations');
        $annotations = is_array($annotations) ? array_values($annotations) : [];

        $result = [
            'message' => $content,
            'provider_request_id' => data_get($body, 'id')
                ?: $response->header('X-Generation-Id'),
            // OpenRouter includes normalized token and cost accounting in the
            // response. Persist the real amount; never infer margin from a
            // model name that can change price later.
            'usage' => [
                'prompt_tokens' => max(0, (int) data_get($body, 'usage.prompt_tokens', 0)),
                'completion_tokens' => max(0, (int) data_get($body, 'usage.completion_tokens', 0)),
                'total_tokens' => max(0, (int) data_get($body, 'usage.total_tokens', 0)),
                'cost' => is_numeric($providerCost) ? max(0, (float) $providerCost) : 0,
                // Zero is a valid provider-reported cost (for example a free
                // model). Keep it distinct from an omitted usage cost.
                'cost_reported' => is_numeric($providerCost),
            ],
            // URL citations belong to the generated answer rather than to an
            // uploaded learner file. Keeping them separate prevents web-search
            // metadata from being written onto attachment records.
            'response_annotations' => array_values(array_filter(
                $annotations,
                static fn (mixed $annotation): bool => is_array($annotation)
                    && strtolower((string) ($annotation['type'] ?? '')) === 'url_citation'
            )),
            'file_annotations' => array_values(array_filter(
                $annotations,
                static fn (mixed $annotation): bool => !is_array($annotation)
                    || strtolower((string) ($annotation['type'] ?? '')) !== 'url_citation'
            )),
            'provider_transport' => [
                'generation_id' => substr(
                    (string) ($response->header('X-Generation-Id') ?: data_get($body, 'id', '')),
                    0,
                    255
                ),
                'response_cache_status' => substr(
                    (string) $response->header('X-OpenRouter-Cache-Status'),
                    0,
                    16
                ),
            ],
        ];

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
            $options['on_headers'] = static function (ResponseInterface $response) use (&$headers, &$isEventStream): void {
                $headers = $response;
                $isEventStream = $response->getStatusCode() >= 200
                    && $response->getStatusCode() < 300
                    && str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/event-stream');
            };
            $options['sink'] = FnStream::decorate($body, [
                'write' => static function (string $chunk) use ($stream, $body, &$isEventStream, &$decoderFailure): int {
                    if (!$isEventStream) {
                        return $body->write($chunk);
                    }
                    try {
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

    private function containsPdf(array $messages): bool
    {
        foreach ($messages as $message) {
            $content = is_array($message) ? ($message['content'] ?? null) : null;
            if (!is_array($content)) continue;
            foreach ($content as $part) {
                if (!is_array($part) || ($part['type'] ?? null) !== 'file') continue;
                if (str_starts_with((string) data_get($part, 'file.file_data'), 'data:application/pdf;')) {
                    return true;
                }
            }
        }
        return false;
    }

    private function learnerVisibleContent(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }
        if (!is_array($content)) {
            return '';
        }

        $lines = [];
        foreach ($content as $part) {
            if (is_string($part) && trim($part) !== '') {
                $lines[] = trim($part);
                continue;
            }
            if (!is_array($part)) {
                continue;
            }

            $type = strtolower(trim((string) ($part['type'] ?? '')));
            if ($type !== '' && !in_array($type, ['text', 'output_text'], true)) {
                continue;
            }
            if (is_string($part['text'] ?? null) && trim($part['text']) !== '') {
                $lines[] = trim($part['text']);
            }
        }

        return implode("\n", $lines);
    }

    private function reasoningEffort(string $model): ?string
    {
        $effort = strtolower(trim((string) config('openrouter.reasoning_effort', 'none')));
        $effort = in_array(
            $effort,
            ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'],
            true
        ) ? $effort : 'none';

        // GPT-5.6 enables medium reasoning by default. That can consume half
        // a short learner-answer budget before any visible text is produced.
        // Its current production variants support disabling reasoning, which
        // keeps first-token latency and billed output predictable.
        if (
            $effort === 'none'
            && preg_match(
                '/^openai\/gpt-5\.6-(?:luna|terra|sol)(?:-pro)?(?:-\d{8})?$/',
                strtolower(trim($model))
            )
        ) {
            return 'none';
        }

        // The original GPT-5 family requires reasoning and advertises
        // minimal/low/medium/high only. OpenRouter may map an unsupported
        // "none" to a larger default effort, consuming the small course-chat
        // completion budget before any learner-visible text is produced.
        if (
            $effort === 'none'
            && preg_match(
                '/^openai\/gpt-5(?:-(?:mini|nano|pro))?(?:-\d{4}-\d{2}-\d{2})?$/',
                strtolower(trim($model))
            )
        ) {
            return 'minimal';
        }

        // `none` is an OpenRouter reasoning control, not a universal model
        // parameter. Omitting it lets ordinary chat models remain eligible
        // when strict parameter support is enabled.
        return $effort === 'none' ? null : $effort;
    }

    private function supportsNoReasoning(string $model): bool
    {
        return preg_match(
            '/^openai\/gpt-5\.6-(?:luna|terra|sol)(?:-pro)?(?:-\d{8})?$/',
            strtolower(trim($model))
        ) === 1;
    }

    private function supportsTemperature(string $model): bool
    {
        $normalized = strtolower(trim($model));

        return !str_starts_with($normalized, 'openai/gpt-5')
            && !preg_match('/^openai\/(?:o1|o3|o4)(?:-|$)/', $normalized);
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
