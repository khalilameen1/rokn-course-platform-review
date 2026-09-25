<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CourseChatTurn;
use App\Models\FeedbackReport;
use App\Models\ProjectFeedbackMessage;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\SupportCaseService;
use App\Services\SupportCaseReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** AI reports enter the existing staffed support queue, not a separate inbox. */
final class AiContentReportController extends Controller
{
    public function store(
        Request $request,
        SupportCaseService $cases,
        ApiResponseService $responses,
        SupportCaseReadService $reads
    ): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(['course_chat', 'project_feedback'])],
            'course_id' => ['required_if:scope,course_chat', 'integer', 'min:1'],
            'client_request_id' => ['required_if:scope,course_chat', 'uuid'],
            'thread_id' => ['required_if:scope,project_feedback', 'string', 'max:64'],
            'message_id' => ['required_if:scope,project_feedback', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $user = auth('api')->user();
        $report = DB::transaction(function () use ($data, $user, $cases): FeedbackReport {
            User::query()->whereKey($user->id)->where('active', true)->lockForUpdate()->firstOrFail();
            if ($data['scope'] === 'course_chat') {
                $turn = CourseChatTurn::query()->where('user_id', $user->id)
                    ->where('course_id', $data['course_id'])
                    ->where('client_request_id', $data['client_request_id'])->firstOrFail();
                $answer = trim((string) $turn->answer);
                $courseId = (int) $turn->course_id;
                $identity = (string) $turn->public_id;
                $context = ['scope' => 'course_chat', 'turn_id' => $identity,
                    'client_request_id' => (string) $turn->client_request_id];
            } else {
                $message = ProjectFeedbackMessage::query()->where('public_id', $data['message_id'])
                    ->where('role', 'assistant')->whereHas('thread', fn ($query) => $query
                        ->where('user_id', $user->id)->where('public_id', $data['thread_id']))
                    ->with('thread')->firstOrFail();
                $answer = trim((string) $message->body);
                $courseId = (int) $message->thread->course_id;
                $identity = (string) $message->public_id;
                $context = ['scope' => 'project_feedback', 'thread_id' => (string) $message->thread->public_id,
                    'message_id' => $identity];
            }
            abort_if($answer === '', 422, 'لا يوجد رد للإبلاغ عنه');
            // One report per learner/output; retries after a lost response cannot
            // flood support. No caller-provided answer or prompt is trusted.
            $hash = hash('sha256', 'ai-report|'.$user->id.'|'.$data['scope'].'|'.$identity);
            $requestId = substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-5'.substr($hash, 13, 3)
                .'-a'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
            $existing = FeedbackReport::query()->where('client_request_id', $requestId)->first();
            if ($existing) return $existing;
            $body = "إبلاغ عن رد بالذكاء الاصطناعي\n"
                .(trim((string) ($data['reason'] ?? '')) ?: 'رد غير مناسب أو غير دقيق')
                ."\n\nالرد المبلّغ عنه:\n".mb_substr($answer, 0, 12000);
            $report = FeedbackReport::query()->create([
                'public_id' => (string) Str::ulid(), 'client_request_id' => $requestId,
                'request_fingerprint' => $hash, 'user_id' => $user->id,
                'course_id' => $courseId, 'category' => 'course_content',
                'status' => 'new', 'priority' => 'high', 'message' => $body,
                'screen_key' => 'ai_content_report', 'context' => ['ai_content' => $context],
                'first_response_due_at' => $cases->firstResponseDueAt('high'),
                'retention_until' => now()->addDays(max(30, (int) config('retention.support_cases_days', 365))),
            ]);
            $cases->event($report, $user->id, 'created', null, 'new');
            $cases->appendLearnerMessage($report, $user, $body, $requestId);
            return $report;
        }, 3);
        return $responses->success($reads->customerPayload($report), 'وصل بلاغك لفريق ركن')
            ->header('Cache-Control', 'no-store');
    }
}
