<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AiProviderUnavailableException;
use Illuminate\Support\Facades\Log;

/** Learner-visible output and evidenced usage, independent of HTTP and settlement. */
final class OpenRouterResponseDecoder
{
    public function decode(
        mixed $body,
        string $model,
        ?string $generationId = null,
        ?string $cacheStatus = null
    ): array {
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

        $providerCost = data_get($body, 'usage.cost');
        $annotations = data_get($body, 'choices.0.message.annotations');
        $annotations = is_array($annotations) ? array_values($annotations) : [];

        $result = [
            'message' => $content,
            'provider_request_id' => data_get($body, 'id')
                ?: $generationId,
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
                    (string) ($generationId ?: data_get($body, 'id', '')),
                    0,
                    255
                ),
                'response_cache_status' => substr(
                    (string) $cacheStatus,
                    0,
                    16
                ),
            ],
        ];

        return $result;
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
}
