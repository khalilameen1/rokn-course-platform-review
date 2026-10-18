<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\ContactRequest;
use App\Models\Contact;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable public contact submission. */
class TasksController extends Controller
{
    public function contact(ContactRequest $request): JsonResponse
    {
        $payload = $request->validated();
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

        $existing = Contact::query()
            ->where('client_request_id', $payload['client_request_id'])
            ->first();
        if ($existing) {
            abort_unless(hash_equals((string) $existing->request_fingerprint, $fingerprint), 409);

            return $this->contactReceipt($payload['client_request_id'], true);
        }

        $payload['request_fingerprint'] = $fingerprint;
        try {
            DB::transaction(static function () use ($payload): void {
                Contact::query()->create($payload);
            });
        } catch (QueryException $exception) {
            $existing = Contact::query()
                ->where('client_request_id', $payload['client_request_id'])
                ->first();
            if (! $existing) {
                throw $exception;
            }
            abort_unless(hash_equals((string) $existing->request_fingerprint, $fingerprint), 409);

            return $this->contactReceipt($payload['client_request_id'], true);
        }

        return $this->contactReceipt($payload['client_request_id'], false);
    }

    private function contactReceipt(string $requestId, bool $replayed): JsonResponse
    {
        return response()->json([
            'status' => $replayed ? 200 : 201,
            'success' => true,
            'message' => 'تم إرسال رسالتك بنجاح',
            'data' => [
                'request_id' => $requestId,
                'replayed' => $replayed,
            ],
        ], $replayed ? 200 : 201);
    }
}
