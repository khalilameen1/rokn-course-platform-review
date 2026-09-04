<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlaybackSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PlaybackSessionService
{
    public function __construct(private PlaybackCapabilityService $capabilities) {}

    public function accept(User $user, int $lessonId, array $sample): array
    {
        $sessionId = (string) ($sample['playback_session_id'] ?? '');
        if ($sessionId === '') {
            return [
                'accepted' => false,
                'reason' => 'invalid_session',
            ];
        }

        return DB::transaction(function () use ($user, $lessonId, $sample, $sessionId): array {
            $session = PlaybackSession::query()->lockForUpdate()->find($sessionId);
            if (!$session || (int) $session->user_id !== (int) $user->id || (int) $session->lesson_id !== $lessonId) {
                return ['accepted' => false, 'reason' => 'invalid_session'];
            }
            if ($session->ended_at !== null) {
                return ['accepted' => false, 'reason' => 'session_ended', 'session' => $session];
            }
            if ($session->started_at && $session->started_at->lt(now()->subHours(12))) {
                $session->forceFill([
                    'event_type' => 'stop',
                    'ended_at' => now(),
                    'end_reason' => 'session_expired',
                ])->save();

                return ['accepted' => false, 'reason' => 'invalid_session'];
            }

            $sequence = (int) ($sample['sequence'] ?? 0);
            if ($sequence <= (int) $session->last_sequence) {
                return ['accepted' => false, 'reason' => 'stale_sequence', 'session' => $session];
            }

            $previousSample = [
                'position_seconds' => (int) $session->last_position_seconds,
                'recorded_at' => $session->last_heartbeat_at,
            ];

            $position = max(0, (int) ($sample['position_seconds'] ?? 0));
            $eventType = (string) ($sample['event_type'] ?? 'heartbeat');
            $isCompleted = !empty($sample['is_completed']) || $eventType === 'complete';
            $isTerminal = $isCompleted
                || $eventType === 'stop'
                || ($eventType === 'error' && !empty($sample['is_terminal']));
            $endReason = $isTerminal
                ? $this->endReason($eventType, $isCompleted, $sample['end_reason'] ?? null)
                : null;
            $capabilities = $this->capabilities->normalize(
                isset($sample['client_capabilities']) && is_array($sample['client_capabilities'])
                    ? $sample['client_capabilities']
                    : null
            );

            $attributes = [
                'last_sequence' => $sequence,
                // This is the last sample, not the furthest seek position.
                // Keeping the maximum made an ordinary rewind suppress all
                // verified progress until the learner crossed the old peak.
                'last_position_seconds' => $position,
                'duration_seconds' => $sample['duration_seconds'] ?? $session->duration_seconds,
                'last_heartbeat_at' => now(),
                'event_type' => $eventType,
                'effective_quality' => $sample['effective_quality'] ?? $session->effective_quality,
                'effective_bitrate_kbps' => $sample['effective_bitrate_kbps'] ?? $session->effective_bitrate_kbps,
                'playback_rate' => $sample['playback_rate'] ?? $session->playback_rate,
                'recovery_count' => max((int) $session->recovery_count, (int) ($sample['recovery_count'] ?? 0)),
                'buffer_count' => max((int) $session->buffer_count, (int) ($sample['buffer_count'] ?? 0)),
                'buffer_duration_ms' => max((int) $session->buffer_duration_ms, (int) ($sample['buffer_duration_ms'] ?? 0)),
                'startup_latency_ms' => $session->startup_latency_ms ?? ($sample['startup_latency_ms'] ?? null),
                'started_playing_at' => $session->started_playing_at
                    ?? (in_array($eventType, ['play', 'start', 'heartbeat'], true) ? now() : null),
                'last_error_code' => isset($sample['error_code'])
                    ? $this->errorCode((string) $sample['error_code'])
                    : $session->last_error_code,
                'diagnostics' => isset($sample['diagnostics'])
                    ? $this->diagnostics((array) $sample['diagnostics'])
                    : $session->diagnostics,
                'ended_at' => $isTerminal ? now() : null,
                'end_reason' => $endReason,
            ];

            if ($capabilities !== []) {
                $attributes['app_version'] = $capabilities['app_version'] ?? $session->app_version;
                $attributes['os_family'] = $capabilities['os'] ?? $session->os_family;
                $attributes['os_version'] = $capabilities['os_version'] ?? $session->os_version;
                $attributes['connection_type'] = $capabilities['connection'] ?? $session->connection_type;
                $attributes['client_capabilities'] = $capabilities;
            }

            $session->forceFill($attributes)->save();

            return [
                'accepted' => true,
                'reason' => 'accepted',
                'trusted_evidence' => true,
                'session' => $session,
                'previous_sample' => $previousSample,
            ];
        });
    }

    private function endReason(string $eventType, bool $isCompleted, mixed $requested): string
    {
        if ($isCompleted) {
            return 'completed';
        }

        $allowed = [
            'user_exit', 'navigation', 'lesson_changed', 'app_closed',
            'playback_error', 'source_expired', 'replaced', 'unknown',
        ];
        if (is_string($requested) && in_array($requested, $allowed, true)) {
            return $requested;
        }

        return $eventType === 'error' ? 'playback_error' : 'user_exit';
    }

    private function errorCode(string $errorCode): ?string
    {
        $errorCode = strtoupper(trim($errorCode));
        if ($errorCode === '') {
            return null;
        }

        return substr((string) preg_replace('/[^A-Z0-9_.-]/', '_', $errorCode), 0, 64);
    }

    /** @return array<string, bool|float|int|string> */
    private function diagnostics(array $diagnostics): array
    {
        $allowed = [
            'stage', 'reason', 'source_type', 'http_status', 'player_error',
            'buffered_seconds', 'manifest_age_seconds', 'retry_stage',
        ];
        $clean = [];
        foreach ($allowed as $key) {
            $value = $diagnostics[$key] ?? null;
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $clean[$key] = $value;
            } elseif (is_string($value) && $value !== '') {
                $value = (string) preg_replace('/https?:\/\/[^\s]+/i', '[url]', $value);
                $value = (string) preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[redacted]', $value);
                $clean[$key] = mb_substr($value, 0, 128);
            }
        }

        return $clean;
    }
}
