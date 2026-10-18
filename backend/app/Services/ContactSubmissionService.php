<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contact;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Durable public contact submission. The client request id makes a retry
 * idempotent; the same id with different content is a conflict.
 */
final readonly class ContactSubmissionService
{
    /**
     * @param  array{client_request_id: string, name: string, email: string, message: string, phone?: string|null}  $payload
     * @return bool true when this request id was already stored (a replay)
     *
     * @throws ConflictHttpException when the request id was used for other content
     */
    public function submit(array $payload): bool
    {
        $payload['phone'] = trim((string) ($payload['phone'] ?? ''));
        $payload['name'] = trim((string) $payload['name']);
        $payload['email'] = Str::lower(trim((string) $payload['email']));
        $payload['message'] = trim((string) $payload['message']);
        $fingerprint = hash('sha256', json_encode([
            'name' => $payload['name'],
            'email' => $payload['email'],
            'phone' => $payload['phone'],
            'message' => $payload['message'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $existing = $this->stored($payload['client_request_id']);
        if ($existing) {
            return $this->replay($existing, $fingerprint);
        }

        $payload['request_fingerprint'] = $fingerprint;
        try {
            DB::transaction(static function () use ($payload): void {
                Contact::query()->create($payload);
            });
        } catch (QueryException $exception) {
            // A concurrent first submission of the same request id won the
            // unique key; answer exactly as a later retry would.
            $existing = $this->stored($payload['client_request_id']);
            if (! $existing) {
                throw $exception;
            }

            return $this->replay($existing, $fingerprint);
        }

        return false;
    }

    private function stored(string $requestId): ?Contact
    {
        return Contact::query()->where('client_request_id', $requestId)->first();
    }

    private function replay(Contact $existing, string $fingerprint): bool
    {
        if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
            throw new ConflictHttpException;
        }

        return true;
    }
}
