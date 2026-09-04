<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\ApiErrorContract;
use App\Support\BusinessClock;
use Illuminate\Http\JsonResponse;

final class PaymentApiResponseService
{
    /**
     * Keep the documented API envelope while mirroring payment fields at the
     * top level for released mobile clients. The payment status endpoint owns
     * the legacy `status` key, so its HTTP code is also exposed as status_code.
     *
     * @param array<string, mixed> $data
     * @param array<string, list<string>>|null $errors
     */
    public function make(
        bool $success,
        string $message,
        array $data = [],
        int $httpStatus = 200,
        ?string $code = null,
        ?array $errors = null
    ): JsonResponse {
        $payload = [
            'status' => $httpStatus,
            'http_status' => $httpStatus,
            'success' => $success,
            'data' => $data === [] ? null : $data,
            'message' => $message,
            'server_time' => BusinessClock::utcNow()->toIso8601String(),
        ];

        if (!$success) {
            $payload['code'] = $code ?? ApiErrorContract::codeForStatus($httpStatus);
            $payload['retryable'] = ApiErrorContract::retryable($httpStatus);
        } elseif ($code !== null) {
            $payload['code'] = $code;
        }
        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        foreach ($data as $key => $value) {
            if ($key === 'status') {
                $payload['status_code'] = $httpStatus;
                $payload['status'] = $value;

                continue;
            }
            if (!array_key_exists($key, $payload)) {
                $payload[$key] = $value;
            }
        }

        return response()->json($payload, $httpStatus);
    }
}
