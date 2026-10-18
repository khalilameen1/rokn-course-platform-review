<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseChatTurn;
use App\Models\FeedbackReport;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\ProjectFeedbackMessage;
use App\Models\User;
use App\Support\PrivacyFingerprint;
use App\Support\SupportCaseSubmissionResult;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Initial case admission and resumable first message, without HTTP response ownership. */
final class SupportCaseSubmissionService
{
    public function __construct(
        private readonly SupportCaseService $cases,
        private readonly SupportCaseAccessService $access,
        private readonly SupportCaseScreenshotService $screenshots
    ) {}

    /**
     * @param  array<string, mixed>  $validated  Validated case fields; transport metadata is separate.
     * @param  array{platform:?string,app_version:?string,build_number:?int,request_id:?string,ip_hash:string,user_agent:?string}  $telemetry
     */
    public function submit(array $validated, ?User $user, ?UploadedFile $screenshot, array $telemetry): SupportCaseSubmissionResult
    {
        $this->validateContextOwnership($validated, $user?->id);
        $credential = $this->access->createGuestCredential($validated['client_request_id']);
        $fingerprint = $this->requestFingerprint($validated, $this->screenshots->fingerprint($screenshot));
        $report = FeedbackReport::query()->where('client_request_id', $validated['client_request_id'])->first();
        $replayed = (bool) $report;
        if ($report) {
            $this->assertReplay($report, $fingerprint, $user);
        } else {
            try {
                $report = DB::transaction(function () use ($validated, $fingerprint, $credential, $user, $telemetry): FeedbackReport {
                    if ($user) {
                        User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                    }
                    $report = FeedbackReport::query()->create([
                        'public_id' => (string) Str::ulid(),
                        'client_request_id' => $validated['client_request_id'],
                        'request_fingerprint' => $fingerprint,
                        'guest_access_hash' => $user ? null : $credential['hash'],
                        'requester_email' => isset($validated['requester_email'])
                            ? strtolower(trim($validated['requester_email'])) : null,
                        'user_id' => $user?->id,
                        'course_id' => $validated['course_id'] ?? null,
                        'lesson_id' => $validated['lesson_id'] ?? null,
                        'order_id' => $validated['order_id'] ?? null,
                        'category' => $validated['category'],
                        'status' => 'new',
                        'priority' => 'normal',
                        'message' => trim($validated['message']),
                        'screen_key' => $validated['screen_key'] ?? null,
                        'platform' => $telemetry['platform'],
                        'app_version' => $telemetry['app_version'],
                        'build_number' => $telemetry['build_number'],
                        'os_major' => $validated['os_major'] ?? null,
                        'locale' => $validated['locale'] ?? null,
                        'screen_size' => $validated['screen_size'] ?? null,
                        'font_scale' => $validated['font_scale'] ?? null,
                        'device_tier' => $validated['device_tier'] ?? null,
                        'network_type' => $validated['network_type'] ?? null,
                        'context' => array_filter(['request_id' => $telemetry['request_id']]),
                        'ip_hash' => $telemetry['ip_hash'],
                        'user_agent' => $telemetry['user_agent'],
                        'first_response_due_at' => $this->cases->firstResponseDueAt(),
                        'retention_until' => now()->addDays(max(30, (int) config('retention.support_cases_days', 365))),
                    ]);
                    $this->cases->event($report, $user?->id, 'created', null, 'new');

                    return $report;
                }, 3);
            } catch (QueryException $exception) {
                $report = FeedbackReport::query()->where('client_request_id', $validated['client_request_id'])->first();
                if (! $report || ! hash_equals((string) $report->request_fingerprint, $fingerprint)) {
                    throw $exception;
                }
                $this->assertReplay($report, $fingerprint, $user);
                $replayed = true;
            }
        }

        // Deliberately after case admission: screenshot staging must commit its
        // orphan ledger before writing bytes. A failed first message resumes on retry.
        $this->cases->appendLearnerMessage(
            $report, $user, trim($validated['message']), $validated['client_request_id'], $screenshot
        );
        if ($user && ! $report->user_id) {
            $this->cases->claim($report, $user, $credential['token']);
        }

        return new SupportCaseSubmissionResult($report->fresh(), $replayed, $user ? null : $credential['token']);
    }

    /**
     * A learner's report about one AI answer, admitted into the staffed
     * support queue. One case per learner and answer: a retry after a lost
     * response returns the same case instead of flooding support. The
     * reported text is read from the stored answer, never from the caller.
     *
     * @param  array{scope:string, course_id?:int|string, client_request_id?:string, thread_id?:string, message_id?:string, reason?:?string}  $validated
     */
    public function reportAiContent(User $user, array $validated): FeedbackReport
    {
        return DB::transaction(function () use ($validated, $user): FeedbackReport {
            User::query()->whereKey($user->id)->where('active', true)->lockForUpdate()->firstOrFail();
            if ($validated['scope'] === 'course_chat') {
                $turn = CourseChatTurn::query()->where('user_id', $user->id)
                    ->where('course_id', $validated['course_id'])
                    ->where('client_request_id', $validated['client_request_id'])->firstOrFail();
                $answer = trim((string) $turn->answer);
                $courseId = (int) $turn->course_id;
                $identity = (string) $turn->public_id;
                $context = ['scope' => 'course_chat', 'turn_id' => $identity,
                    'client_request_id' => (string) $turn->client_request_id];
            } else {
                $message = ProjectFeedbackMessage::query()->where('public_id', $validated['message_id'])
                    ->where('role', 'assistant')->whereHas('thread', fn ($query) => $query
                    ->where('user_id', $user->id)->where('public_id', $validated['thread_id']))
                    ->with('thread')->firstOrFail();
                $thread = $message->getRelation('thread');
                $answer = trim((string) $message->body);
                $courseId = (int) $thread->course_id;
                $identity = (string) $message->public_id;
                $context = ['scope' => 'project_feedback', 'thread_id' => (string) $thread->public_id,
                    'message_id' => $identity];
            }
            abort_if($answer === '', 422, 'لا يوجد رد للإبلاغ عنه');
            $hash = hash('sha256', 'ai-report|'.$user->id.'|'.$validated['scope'].'|'.$identity);
            $requestId = substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-5'.substr($hash, 13, 3)
                .'-a'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
            $existing = FeedbackReport::query()->where('client_request_id', $requestId)->first();
            if ($existing) {
                return $existing;
            }
            $body = "إبلاغ عن رد بالذكاء الاصطناعي\n"
                .(trim((string) ($validated['reason'] ?? '')) ?: 'رد غير مناسب أو غير دقيق')
                ."\n\nالرد المبلّغ عنه:\n".mb_substr($answer, 0, 12000);
            $report = FeedbackReport::query()->create([
                'public_id' => (string) Str::ulid(), 'client_request_id' => $requestId,
                'request_fingerprint' => $hash, 'user_id' => $user->id,
                'course_id' => $courseId, 'category' => 'course_content',
                'status' => 'new', 'priority' => 'high', 'message' => $body,
                'screen_key' => 'ai_content_report', 'context' => ['ai_content' => $context],
                'first_response_due_at' => $this->cases->firstResponseDueAt('high'),
                'retention_until' => now()->addDays(max(30, (int) config('retention.support_cases_days', 365))),
            ]);
            $this->cases->event($report, $user->id, 'created', null, 'new');
            $this->cases->appendLearnerMessage($report, $user, $body, $requestId);

            return $report;
        }, 3);
    }

    /**
     * A visitor's report about a public portfolio. A short duplicate window
     * absorbs back/refresh without suppressing later reports.
     *
     * @param  array{message:string, email?:?string}  $validated
     */
    public function reportPublicPortfolio(string $slug, array $validated, ?string $ip, ?string $userAgent): void
    {
        $owner = User::query()->where('portfolio_slug', $slug)->firstOrFail();
        $fingerprint = hash_hmac('sha256', implode('|', [
            $slug, trim($validated['message']), (string) $ip,
        ]), (string) config('app.key'));
        if (FeedbackReport::query()->where('request_fingerprint', $fingerprint)
            ->where('created_at', '>=', now()->subMinutes(10))->exists()) {
            return;
        }
        DB::transaction(function () use ($validated, $owner, $slug, $fingerprint, $ip, $userAgent): void {
            $report = FeedbackReport::query()->create([
                'public_id' => (string) Str::ulid(),
                'client_request_id' => (string) Str::uuid(),
                'request_fingerprint' => $fingerprint,
                'requester_email' => isset($validated['email']) ? strtolower(trim($validated['email'])) : null,
                'category' => 'course_content',
                'status' => 'new',
                'priority' => 'high',
                'message' => trim($validated['message']),
                'screen_key' => 'public_portfolio_report',
                'platform' => 'web',
                'locale' => 'ar',
                'context' => ['portfolio_owner_id' => $owner->id, 'portfolio_slug' => $slug],
                'ip_hash' => PrivacyFingerprint::make($ip),
                'user_agent' => PrivacyFingerprint::make($userAgent),
                'first_response_due_at' => $this->cases->firstResponseDueAt('high'),
                'retention_until' => now()->addDays(max(30, (int) config('retention.support_cases_days', 365))),
            ]);
            $this->cases->event($report, null, 'created', null, 'new');
        });
    }

    private function assertReplay(FeedbackReport $report, string $fingerprint, ?User $user): void
    {
        abort_unless(hash_equals((string) $report->request_fingerprint, $fingerprint), 409);
        abort_if($report->user_id && (int) $report->user_id !== (int) $user?->id, 409);
    }

    private function validateContextOwnership(array $validated, ?int $userId): void
    {
        if (! empty($validated['lesson_id']) && ! empty($validated['course_id'])) {
            abort_unless(
                Lesson::query()->whereKey($validated['lesson_id'])
                    ->where('list_id', $validated['course_id'])->exists(),
                422
            );
        }
        if (! empty($validated['order_id'])) {
            abort_unless(
                $userId && Order::query()->whereKey($validated['order_id'])
                    ->where('user_id', $userId)->exists(),
                422
            );
        }
    }

    private function requestFingerprint(array $validated, ?array $screenshot): string
    {
        return hash('sha256', json_encode([
            'category' => $validated['category'],
            'message' => trim($validated['message']),
            'screen_key' => $validated['screen_key'] ?? null,
            'course_id' => $validated['course_id'] ?? null,
            'lesson_id' => $validated['lesson_id'] ?? null,
            'order_id' => $validated['order_id'] ?? null,
            'requester_email' => strtolower(trim((string) ($validated['requester_email'] ?? ''))),
            'screenshot' => $screenshot,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
