<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger as IlluminateLogger;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use Throwable;

final class RedactSensitiveContext
{
    private const REDACTED = '[redacted]';

    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'authorization',
        'proxy_authorization',
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'access_token',
        'refresh_token',
        'id_token',
        'api_token',
        'device_token',
        'purchase_token',
        'encrypted_token',
        'client_secret',
        'secret',
        'secret_key',
        'api_key',
        'bunny_api_key',
        'bunny_storage_password',
        'bunny_security_key',
        'signature',
        'transaction_id',
        'provider_transaction_id',
        'external_transaction_id',
        'original_transaction_id',
        'card_number',
        'cardholder_name',
        'cvv',
        'cvc',
        'email',
        'phone',
        'phone_e164',
        'sender_phone_e164',
        'parent_phone',
        'whatsapp',
    ];

    public function __invoke(IlluminateLogger|MonologLogger $logger): void
    {
        $monolog = $logger instanceof IlluminateLogger ? $logger->getLogger() : $logger;
        $monolog->pushProcessor(fn (LogRecord|array $record): LogRecord|array => $this->redactRecord($record));
    }

    private function redactRecord(LogRecord|array $record): LogRecord|array
    {
        if ($record instanceof LogRecord) {
            return $record->with(
                message: $this->redactText($record->message),
                context: $this->redactValue($record->context),
                extra: $this->redactValue($record->extra)
            );
        }

        $record['message'] = $this->redactText((string) ($record['message'] ?? ''));
        $record['context'] = $this->redactValue($record['context'] ?? []);
        $record['extra'] = $this->redactValue($record['extra'] ?? []);

        return $record;
    }

    private function redactValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return self::REDACTED;
        }

        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $childKey => $childValue) {
                $redacted[$childKey] = $this->redactValue(
                    $childValue,
                    is_string($childKey) ? $childKey : null
                );
            }

            return $redacted;
        }

        if ($value instanceof Throwable) {
            $rawMessage = $value->getMessage();
            $isDatabaseFailure = is_a($value, 'Illuminate\\Database\\QueryException');

            return [
                'class' => $value::class,
                'message' => $isDatabaseFailure
                    ? 'Database query failed'
                    : $this->redactText($rawMessage),
                'fingerprint' => hash('sha256', $value::class.'|'.$rawMessage),
                'file' => $value->getFile(),
                'line' => $value->getLine(),
                // Exception arguments can contain request payloads and provider
                // credentials. Keep the useful call path without serializing
                // any argument values into a log sink.
                'trace' => array_map(
                    static fn (array $frame): array => array_filter([
                        'file' => $frame['file'] ?? null,
                        'line' => $frame['line'] ?? null,
                        'call' => isset($frame['function'])
                            ? (string) ($frame['class'] ?? '').(string) ($frame['type'] ?? '').(string) $frame['function']
                            : null,
                    ], static fn (mixed $part): bool => $part !== null && $part !== ''),
                    $value->getTrace()
                ),
            ];
        }

        if (is_string($value)) {
            return $this->redactText($value);
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '.'], '_', trim($key)));

        return in_array($normalized, self::SENSITIVE_KEYS, true)
            || str_ends_with($normalized, '_password')
            || str_ends_with($normalized, '_secret')
            || str_ends_with($normalized, '_access_token')
            || str_ends_with($normalized, '_refresh_token');
    }

    private function redactText(string $value): string
    {
        $value = preg_replace(
            '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i',
            'Bearer '.self::REDACTED,
            $value
        ) ?? $value;
        $value = preg_replace(
            '/([?&](?:access_token|refresh_token|id_token|api_token|device_token|purchase_token|client_secret|secret|signature|code)=)[^&\s]*/i',
            '$1'.self::REDACTED,
            $value
        ) ?? $value;
        $value = preg_replace_callback(
            '/\b(authorization|password(?:_confirmation)?|current_password|new_password|access_token|refresh_token|id_token|api_token|device_token|purchase_token|client_secret|secret(?:_key)?|api_key|signature|card_number|cardholder_name|cvv|cvc|email|phone(?:_e164)?|sender_phone_e164|parent_phone|whatsapp)\b\s*(=|:)\s*(["\']?)[^,"\'&\s}\]]+/i',
            static fn (array $matches): string => $matches[1].$matches[2].self::REDACTED,
            $value
        ) ?? $value;
        $value = preg_replace(
            '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i',
            self::REDACTED,
            $value
        ) ?? $value;

        return $value;
    }
}
