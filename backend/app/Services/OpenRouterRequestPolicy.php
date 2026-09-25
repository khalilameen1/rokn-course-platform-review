<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AiProviderUnavailableException;

/** Model selection and the provider request contract, without network or cache access. */
final class OpenRouterRequestPolicy
{
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

    /** Build one request; provider-side fallbacks never become local paid retries. */
    public function payload(
        string $model,
        array $messages,
        float $temperature,
        int $maxTokens,
        ?string $requestIdentity = null,
        bool $allowWebSearch = false,
        bool $stream = false
    ): array {
        $allowed = array_values(array_filter(config('openrouter.allowed_models', [])));
        if ($allowed === [] || !in_array($model, $allowed, true)) {
            throw new AiProviderUnavailableException(
                false,
                'AI model is not in the production allowlist.',
                providerCode: 'model_not_allowed'
            );
        }

        $reasoningEffort = $this->reasoningEffort($model);
        $disableSonnetReasoning = $reasoningEffort === 'none' && $this->isSonnetFive($model);
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
                    || ($disableSonnetReasoning && $this->isSonnetFive($candidate))
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
        if ($disableSonnetReasoning) {
            // Sonnet 5 advertises optional thinking, but no `none` effort.
            // Omission enables its high default; disable it explicitly.
            $payload['reasoning'] = ['enabled' => false, 'exclude' => true];
        } elseif ($reasoningEffort !== null) {
            $payload['reasoning'] = [
                'effort' => $reasoningEffort,
                'exclude' => true,
            ];
        }
        // GPT-5 and Sonnet 5 do not advertise temperature support. Sending it
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
        if ($stream) {
            $payload['stream'] = true;
            $payload['stream_options'] = ['include_usage' => true];
        }

        return $payload;
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

    private function reasoningEffort(string $model): ?string
    {
        $effort = strtolower(trim((string) config('openrouter.reasoning_effort', 'none')));
        $effort = in_array(
            $effort,
            ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'],
            true
        ) ? $effort : 'none';

        if ($this->isGeminiThreeEightFlash($model)) {
            // Gemini 3.8 requires thinking and accepts only these three levels.
            // Keep the legacy no-thinking chat setting on its lowest latency level.
            return match ($effort) {
                'medium' => 'medium',
                'high', 'xhigh', 'max' => 'high',
                default => 'low',
            };
        }

        if ($this->isSonnetFive($model)) {
            return $effort === 'minimal' ? 'low' : $effort;
        }

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

    private function isSonnetFive(string $model): bool
    {
        return strtolower(trim($model)) === 'anthropic/claude-sonnet-5';
    }

    private function supportsTemperature(string $model): bool
    {
        $normalized = strtolower(trim($model));

        return !str_starts_with($normalized, 'openai/gpt-5')
            && !$this->isSonnetFive($normalized)
            && !$this->isGeminiThreeEightFlash($normalized)
            && !preg_match('/^openai\/(?:o1|o3|o4)(?:-|$)/', $normalized);
    }

    private function isGeminiThreeEightFlash(string $model): bool
    {
        return strtolower(trim($model)) === 'google/gemini-3.8-flash';
    }
}
