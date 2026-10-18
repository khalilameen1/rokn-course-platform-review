<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\ContactRequest;
use App\Services\ApiResponseService;
use App\Services\ContactSubmissionService;
use Illuminate\Http\JsonResponse;

/** Durable public contact submission. */
final class TasksController extends Controller
{
    public function contact(
        ContactRequest $request,
        ContactSubmissionService $contacts,
        ApiResponseService $responses
    ): JsonResponse {
        $payload = $request->validated();
        $replayed = $contacts->submit($payload);

        return $responses->success([
            'request_id' => $payload['client_request_id'],
            'replayed' => $replayed,
        ], 'تم إرسال رسالتك بنجاح', $replayed ? 200 : 201);
    }
}
