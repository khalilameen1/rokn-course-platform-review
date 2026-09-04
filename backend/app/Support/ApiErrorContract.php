<?php

declare(strict_types=1);

namespace App\Support;

final class ApiErrorContract
{
    public static function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404, 410 => 'not_found',
            405 => 'method_not_allowed',
            408 => 'request_timeout',
            409 => 'conflict',
            413 => 'payload_too_large',
            422 => 'validation_failed',
            429 => 'rate_limited',
            500 => 'server_error',
            502 => 'upstream_error',
            503 => 'service_unavailable',
            504 => 'upstream_timeout',
            default => $status >= 500 ? 'server_error' : 'request_failed',
        };
    }

    public static function retryable(int $status): bool
    {
        return in_array($status, [408, 425, 429, 500, 502, 503, 504], true);
    }
}
