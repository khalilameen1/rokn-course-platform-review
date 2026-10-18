<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ClientEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ClientEventController extends Controller
{
    private const EVENTS = [
        'app_start',
        'app_crash',
        'api_failure',
        'video_failure',
        'payment_flow_failure',
        'project_upload_failure',
        'authentication_failure',
    ];

    private const FIELDS = [
        'client_event_id',
        'event_name',
        'severity',
        'app_version',
        'build_number',
        'platform',
        'os_major',
        'device_tier',
        'network_type',
        'screen_key',
        'error_code',
        'error_fingerprint',
        'endpoint',
        'request_id',
        'occurred_at',
    ];

    public function store(Request $request): JsonResponse
    {
        $unknownFields = array_values(array_diff(array_keys($request->all()), self::FIELDS));
        if ($unknownFields !== []) {
            // Client events use a closed schema so private data cannot slip in.
            throw ValidationException::withMessages([
                'payload' => ['Unsupported client-event fields: '.implode(', ', $unknownFields)],
            ]);
        }

        $data = $request->validate([
            'client_event_id' => ['required', 'uuid'],
            'event_name' => ['required', Rule::in(self::EVENTS)],
            'severity' => ['sometimes', Rule::in(['info', 'warning', 'error', 'fatal'])],
            'app_version' => ['nullable', 'string', 'max:32', 'regex:/^[0-9A-Za-z._-]+$/'],
            'build_number' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'platform' => ['required', Rule::in(['android', 'ios'])],
            'os_major' => ['nullable', 'integer', 'min:1', 'max:255'],
            'device_tier' => ['sometimes', Rule::in(['low', 'mid', 'high', 'unknown'])],
            'network_type' => ['nullable', Rule::in(['wifi', 'cellular', 'ethernet', 'offline', 'unknown'])],
            'screen_key' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9._-]+$/'],
            'error_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Z0-9._-]+$/'],
            'error_fingerprint' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'endpoint' => ['nullable', 'string', 'max:160', 'regex:/^\/[a-z0-9._:\/-]+$/'],
            'request_id' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/'],
            'occurred_at' => ['required', 'date', 'after_or_equal:-7 days', 'before_or_equal:+10 minutes'],
        ]);

        $userId = null;
        if (trim((string) $request->bearerToken()) !== '') {
            try {
                $userId = auth('api')->id();
            } catch (Throwable) {
                // An expired login may itself be the failure being reported.
            }
        }

        try {
            ClientEvent::query()->firstOrCreate(
                ['client_event_id' => $data['client_event_id']],
                [
                    'event_name' => $data['event_name'],
                    'severity' => $data['severity'] ?? 'info',
                    'app_version' => $data['app_version'] ?? null,
                    'build_number' => $data['build_number'] ?? null,
                    'platform' => $data['platform'],
                    'os_major' => $data['os_major'] ?? null,
                    'device_tier' => $data['device_tier'] ?? 'unknown',
                    'network_type' => $data['network_type'] ?? null,
                    'screen_key' => $data['screen_key'] ?? null,
                    'error_code' => $data['error_code'] ?? null,
                    'error_fingerprint' => $data['error_fingerprint'] ?? null,
                    'endpoint' => $data['endpoint'] ?? null,
                    'request_id' => $data['request_id'] ?? null,
                    'user_id' => $userId,
                    'occurred_at' => $data['occurred_at'],
                    'received_at' => now(),
                ]
            );
        } catch (QueryException $exception) {
            Log::warning('Client event could not be persisted', [
                'event_name' => $data['event_name'],
                'sql_state' => $exception->errorInfo[0] ?? null,
            ]);

            // The client outbox retries 5xx. Returning a false 202 here made a
            // database outage silently erase the only evidence of the outage.
            return response()->json([
                'status' => 503,
                'success' => false,
                'message' => 'تعذر حفظ الحدث الآن',
                'data' => ['accepted' => false, 'retryable' => true],
                'accepted' => false,
                'retryable' => true,
            ], 503);
        }

        return response()->json([
            'status' => 202,
            'success' => true,
            'message' => 'تم حفظ الحدث',
            'data' => ['accepted' => true],
            'accepted' => true,
        ], 202);
    }
}
