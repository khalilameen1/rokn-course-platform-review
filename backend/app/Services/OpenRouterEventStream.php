<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AiProviderUnavailableException;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/** Incremental SSE decoding shared by live HTTP fragments and buffered responses. */
final class OpenRouterEventStream
{
    private string $buffer = '';
    private string $content = '';
    private string $providerRequestId = '';
    private mixed $finishReason = null;
    private mixed $nativeFinishReason = null;
    private array $usage = [];
    private array $annotations = [];
    private int $lastEmittedLength = 0;
    private int $lastEmittedAt;
    private bool $completed = false;
    private bool $receivedFragments = false;
    private mixed $callback;

    public function __construct(callable $onPartial, private string $model)
    {
        $this->callback = $onPartial;
        $this->lastEmittedAt = hrtime(true);
    }

    public function append(string $chunk): void
    {
        $this->receivedFragments = true;
        if ($this->completed) {
            return;
        }
        $this->buffer .= $chunk;
        foreach ($this->takeCompleteSseEvents() as $event) {
            if ($this->consumeSseEvent($event)) {
                $this->completed = true;
                break;
            }
            $this->emitPartial();
        }
    }

    public function completed(): bool
    {
        return $this->completed;
    }

    public function receivedFragments(): bool
    {
        return $this->receivedFragments;
    }

    public function finish(): array
    {
        try {
            if (!$this->completed && trim($this->buffer) !== '') {
                $this->completed = $this->consumeSseEvent($this->buffer);
                $this->buffer = '';
            }
            if (!$this->completed && !filled($this->finishReason)) {
                throw new AiProviderUnavailableException(
                    false,
                    'AI provider stream ended before completion.',
                    outcomeUnknown: true
                );
            }

            return [
                'id' => $this->providerRequestId,
                'model' => $this->model,
                'choices' => [[
                    'message' => ['content' => $this->content, 'annotations' => $this->annotations],
                    'finish_reason' => $this->finishReason,
                    'native_finish_reason' => $this->nativeFinishReason,
                ]],
                'usage' => $this->usage,
            ];
        } finally {
            $this->flush();
        }
    }

    /** Save visible recovery text without marking a failed generation complete. */
    public function flush(): void
    {
        if (mb_strlen($this->content) > $this->lastEmittedLength) {
            $this->emitPartial(true);
        }
    }

    /** @return list<string> */
    private function takeCompleteSseEvents(): array
    {
        $events = [];
        while (preg_match('/\r\n\r\n|\n\n|\r\r/', $this->buffer, $match, PREG_OFFSET_CAPTURE)) {
            $delimiter = (string) $match[0][0];
            $offset = (int) $match[0][1];
            $events[] = substr($this->buffer, 0, $offset);
            $this->buffer = substr($this->buffer, $offset + strlen($delimiter));
        }

        return $events;
    }

    private function consumeSseEvent(string $event): bool
    {
        $dataLines = [];
        foreach (preg_split('/\r\n|\n|\r/', $event) ?: [] as $line) {
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $dataLines[] = ltrim(substr($line, 5), ' ');
        }
        if ($dataLines === []) {
            return false;
        }

        $payload = implode("\n", $dataLines);
        if (trim($payload) === '[DONE]') {
            return true;
        }

        try {
            $frame = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AiProviderUnavailableException(
                false,
                'AI provider returned a malformed stream.',
                previous: $exception,
                outcomeUnknown: true
            );
        }
        if (!is_array($frame)) {
            return false;
        }
        if (isset($frame['error'])) {
            $rawCode = trim((string) data_get($frame, 'error.code', ''));
            $providerStatus = ctype_digit($rawCode) ? (int) $rawCode : null;
            $outcomeUnknown = $this->content !== '';
            throw new AiProviderUnavailableException(
                !$outcomeUnknown && (
                    in_array($providerStatus, [408, 429], true)
                    || ($providerStatus !== null && $providerStatus >= 500)
                ),
                'AI provider stream returned an error.',
                fileAnnotations: is_array(data_get($frame, 'error.metadata.file_annotations'))
                    ? data_get($frame, 'error.metadata.file_annotations') : [],
                outcomeUnknown: $outcomeUnknown,
                providerStatus: $providerStatus,
                providerCode: $rawCode !== '' ? substr($rawCode, 0, 80) : null
            );
        }

        $this->providerRequestId = (string) ($frame['id'] ?? $this->providerRequestId);
        $this->model = (string) ($frame['model'] ?? $this->model);
        $delta = data_get($frame, 'choices.0.delta.content');
        $this->content .= $this->streamVisibleContent($delta);
        if (mb_strlen($this->content) > 12000) {
            throw new AiProviderUnavailableException(
                false,
                'AI provider stream exceeded the answer limit.',
                outcomeUnknown: true
            );
        }
        $this->finishReason = data_get($frame, 'choices.0.finish_reason', $this->finishReason);
        $this->nativeFinishReason = data_get(
            $frame,
            'choices.0.native_finish_reason',
            $this->nativeFinishReason
        );
        if (is_array($frame['usage'] ?? null)) {
            $this->usage = $frame['usage'];
        }
        $frameAnnotations = data_get($frame, 'choices.0.delta.annotations');
        if (!is_array($frameAnnotations)) {
            $frameAnnotations = data_get($frame, 'choices.0.message.annotations');
        }
        if (is_array($frameAnnotations) && $frameAnnotations !== []) {
            $this->annotations = array_values(array_merge($this->annotations, $frameAnnotations));
        }

        return false;
    }

    private function streamVisibleContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }

        $text = '';
        foreach ($content as $part) {
            if (is_string($part)) {
                $text .= $part;
                continue;
            }
            if (!is_array($part)) {
                continue;
            }
            $type = strtolower(trim((string) ($part['type'] ?? '')));
            if ($type !== '' && !in_array($type, ['text', 'output_text'], true)) {
                continue;
            }
            if (is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        return $text;
    }

    private function emitPartial(bool $force = false): void
    {
        if ($this->callback === null || $this->content === '') {
            return;
        }
        $length = mb_strlen($this->content);
        if ($length <= $this->lastEmittedLength) {
            return;
        }
        $now = hrtime(true);
        if (
            !$force
            && $this->lastEmittedLength > 0
            && $length - $this->lastEmittedLength < 48
            && $now - $this->lastEmittedAt < 250_000_000
        ) {
            return;
        }
        $partial = (string) preg_replace(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',
            '',
            $this->content
        );
        if ($partial === '') {
            return;
        }
        try {
            ($this->callback)($partial);
            $this->lastEmittedLength = $length;
            $this->lastEmittedAt = $now;
        } catch (Throwable $exception) {
            // A progress checkpoint is deliberately non-authoritative. Losing
            // it must not abort a paid provider call whose final result can
            // still be landed and settled safely.
            Log::warning('AI partial response checkpoint failed.', [
                'exception' => $exception::class,
            ]);
            $this->callback = null;
        }
    }
}
