<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\SupportCaseReadService;
use App\Services\SupportCaseSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** AI reports enter the existing staffed support queue, not a separate inbox. */
final class AiContentReportController extends Controller
{
    public function store(
        Request $request,
        SupportCaseSubmissionService $submissions,
        ApiResponseService $responses,
        SupportCaseReadService $reads
    ): JsonResponse {
        $data = $request->validate([
            'scope' => ['required', Rule::in(['course_chat', 'project_feedback'])],
            'course_id' => ['required_if:scope,course_chat', 'integer', 'min:1'],
            'client_request_id' => ['required_if:scope,course_chat', 'uuid'],
            'thread_id' => ['required_if:scope,project_feedback', 'string', 'max:64'],
            'message_id' => ['required_if:scope,project_feedback', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        /** @var User $user */
        $user = auth('api')->user();
        $report = $submissions->reportAiContent($user, $data);

        return $responses->success($reads->customerPayload($report), 'وصل بلاغك لفريق ركن')
            ->header('Cache-Control', 'no-store');
    }
}
