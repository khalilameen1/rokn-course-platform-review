<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\FeedbackReport;
use App\Models\FeedbackAttachment;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\SupportCaseService;
use App\Support\PrivacyFingerprint;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Http\Response;

final class FeedbackController extends Controller
{
    public function index(Request $request, SupportCaseService $cases, ApiResponseService $responses): JsonResponse
    {
        $user = auth('api')->user();
        abort_unless($user, 401);
        $validated = $request->validate(['page' => 'nullable|integer|min:1|max:100000']);
        $reports = FeedbackReport::query()
            ->where('user_id', $user->id)
            ->latest('updated_at')->latest('id')
            ->paginate(20, ['*'], 'page', (int) ($validated['page'] ?? 1));

        return $responses->success([
            'items' => collect($reports->items())
                ->map(fn (FeedbackReport $report) => $cases->customerPayload($report))->all(),
            'pagination' => [
                'current_page' => $reports->currentPage(),
                'last_page' => $reports->lastPage(),
                'has_more' => $reports->hasMorePages(),
            ],
        ], 'تم تحميل الحالات');
    }

    public function store(Request $request, SupportCaseService $cases, ApiResponseService $responses): JsonResponse
    {
        $this->ensureRequestIdentity($request);
        $validated = $request->validate($this->writeRules());
        $user = auth('api')->user();
        $this->validateContextOwnership($validated, $user?->id);
        $credential = $cases->createGuestCredential($validated['client_request_id']);
        $requestFingerprint = $this->requestFingerprint($validated, $request);
        $clientRelease = $this->trustedClientRelease($request);

        $report = FeedbackReport::query()->where('client_request_id', $validated['client_request_id'])->first();
        $replayed = (bool) $report;
        if ($report) {
            abort_unless(hash_equals((string) $report->request_fingerprint, $requestFingerprint), 409);
            abort_if($report->user_id && (int) $report->user_id !== (int) $user?->id, 409);
        } else {
            try {
                $report = DB::transaction(function () use (
                    $request, $validated, $requestFingerprint, $credential, $user, $cases, $clientRelease
                ): FeedbackReport {
                    if ($user) {
                        User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                    }
                    $report = FeedbackReport::query()->create([
                        'public_id' => (string) Str::ulid(),
                        'client_request_id' => $validated['client_request_id'],
                        'request_fingerprint' => $requestFingerprint,
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
                        'platform' => $clientRelease['platform'],
                        'app_version' => $clientRelease['app_version'],
                        'build_number' => $clientRelease['build_number'],
                        'os_major' => $validated['os_major'] ?? null,
                        'locale' => $validated['locale'] ?? null,
                        'screen_size' => $validated['screen_size'] ?? null,
                        'font_scale' => $validated['font_scale'] ?? null,
                        'device_tier' => $validated['device_tier'] ?? null,
                        'network_type' => $validated['network_type'] ?? null,
                        'context' => array_filter([
                            'request_id' => PrivacyFingerprint::make($request->header('X-Request-Id')),
                        ]),
                        'ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
                        'user_agent' => PrivacyFingerprint::make($request->userAgent()),
                        'first_response_due_at' => $cases->firstResponseDueAt(),
                        'retention_until' => now()->addDays(max(30, (int) config('retention.support_cases_days', 365))),
                    ]);
                    $cases->event($report, $user?->id, 'created', null, 'new');
                    return $report;
                }, 3);
            } catch (QueryException $exception) {
                $report = FeedbackReport::query()->where('client_request_id', $validated['client_request_id'])->first();
                if (!$report || !hash_equals((string) $report->request_fingerprint, $requestFingerprint)) {
                    throw $exception;
                }
                $replayed = true;
            }
        }

        $cases->appendLearnerMessage(
            $report,
            $user,
            trim($validated['message']),
            $validated['client_request_id'],
            $request->file('screenshot')
        );

        if ($user && !$report->user_id) {
            DB::transaction(function () use ($report, $user, $cases): void {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $locked = FeedbackReport::query()->lockForUpdate()->findOrFail($report->id);
                if (!$locked->user_id) {
                    $locked->update([
                        'user_id' => $user->id,
                        'guest_access_hash' => null,
                        'version' => (int) $locked->version + 1,
                    ]);
                    $cases->event($locked, $user->id, 'claimed');
                }
            }, 3);
        }

        return $this->receipt(
            $report->fresh(), $cases, $responses, $replayed, $user ? null : $credential['token']
        );
    }

    public function show(
        Request $request,
        string $publicId,
        SupportCaseService $cases,
        ApiResponseService $responses
    ): JsonResponse {
        $report = FeedbackReport::query()->where('public_id', $publicId)->firstOrFail();
        $cases->authorizeViewer($report, auth('api')->user(), $cases->accessTokenFromRequest($request));
        return $responses->success($cases->customerPayload($report), 'تم تحميل الحالة');
    }

    public function reply(
        Request $request,
        string $publicId,
        SupportCaseService $cases,
        ApiResponseService $responses
    ): JsonResponse {
        $this->ensureRequestIdentity($request);
        $validated = $request->validate([
            'client_request_id' => ['required', 'uuid'],
            'message' => ['required', 'string', 'min:2', 'max:2000'],
            'screenshot' => $this->screenshotRules(),
        ]);
        $report = FeedbackReport::query()->where('public_id', $publicId)->firstOrFail();
        $user = auth('api')->user();
        $cases->authorizeViewer($report, $user, $cases->accessTokenFromRequest($request));
        $cases->appendLearnerMessage(
            $report, $user, trim($validated['message']), $validated['client_request_id'], $request->file('screenshot')
        );
        return $responses->success($cases->customerPayload($report->fresh()), 'تم إرسال الرد');
    }

    public function attachment(
        string $publicId,
        int $attachment
    ): Response {
        $report = FeedbackReport::query()->where('public_id', $publicId)->firstOrFail();
        $file = FeedbackAttachment::query()
            ->whereKey($attachment)
            ->where('feedback_report_id', $report->id)
            ->where('scan_status', 'sanitized')
            ->firstOrFail();
        $storage = Storage::disk((string) $file->disk);
        abort_unless($storage->exists((string) $file->path), 410);
        $bytes = $storage->get((string) $file->path);
        if ($file->sha256 && !hash_equals((string) $file->sha256, hash('sha256', $bytes))) {
            $file->update(['scan_status' => 'corrupt']);
            abort(410);
        }
        $name = 'rokn-support-' . strtoupper(substr($publicId, -8))
            . '-' . $file->id . '.jpg';
        return response($bytes, 200, [
            'Content-Type' => (string) ($file->mime_type ?: 'image/jpeg'),
            'Content-Disposition' => 'inline; filename="' . $name . '"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function claim(
        Request $request,
        string $publicId,
        SupportCaseService $cases,
        ApiResponseService $responses
    ): JsonResponse {
        $user = auth('api')->user();
        abort_unless($user, 401);
        $report = FeedbackReport::query()->where('public_id', $publicId)->firstOrFail();
        $cases->authorizeViewer($report, $user, $cases->accessTokenFromRequest($request));
        DB::transaction(function () use ($report, $user, $cases): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked = FeedbackReport::query()->lockForUpdate()->findOrFail($report->id);
            abort_if($locked->user_id && (int) $locked->user_id !== (int) $user->id, 404);
            if (!$locked->user_id) {
                $locked->update([
                    'user_id' => $user->id,
                    'guest_access_hash' => null,
                    'version' => (int) $locked->version + 1,
                ]);
                $cases->event($locked, $user->id, 'claimed');
            }
        }, 3);
        return $responses->success($cases->customerPayload($report->fresh()), 'أضيف البلاغ إلى حسابك');
    }

    private function receipt(
        FeedbackReport $report,
        SupportCaseService $cases,
        ApiResponseService $responses,
        bool $replayed,
        ?string $accessToken
    ): JsonResponse {
        $payload = $cases->customerPayload($report) + ['replayed' => $replayed];
        if ($accessToken) $payload['access_token'] = $accessToken;
        return $responses->success($payload, 'وصلتنا رسالتك', $replayed ? 200 : 201);
    }

    private function writeRules(): array
    {
        return [
            'client_request_id' => ['required', 'uuid'],
            'category' => ['required', Rule::in(['bug', 'suggestion', 'course_content', 'playback'])],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
            'requester_email' => ['nullable', 'email:rfc', 'max:254'],
            'screen_key' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9._-]+$/'],
            'course_id' => 'nullable|integer|exists:courses,id',
            'lesson_id' => 'nullable|integer|exists:lessons,id',
            'order_id' => 'nullable|integer|exists:orders,id',
            'platform' => ['nullable', Rule::in(['android', 'ios', 'web'])],
            'app_version' => 'nullable|string|max:32',
            'build_number' => 'nullable|integer|min:1|max:2147483647',
            'os_major' => 'nullable|integer|min:1|max:255',
            'locale' => 'nullable|string|max:16',
            'screen_size' => ['nullable', 'string', 'max:32', 'regex:/^\d{2,5}x\d{2,5}$/'],
            'font_scale' => 'nullable|numeric|min:0.5|max:4',
            'device_tier' => ['nullable', Rule::in(['low', 'mid', 'high', 'unknown'])],
            'network_type' => ['nullable', Rule::in(['wifi', 'cellular', 'ethernet', 'offline', 'unknown'])],
            'screenshot' => $this->screenshotRules(),
        ];
    }

    private function screenshotRules(): array
    {
        return [
            'nullable', 'file', 'min:1', 'image', 'mimes:jpeg,jpg,png,webp',
            'mimetypes:image/jpeg,image/png,image/webp', 'max:4096',
            'dimensions:max_width=4096,max_height=4096',
        ];
    }

    private function ensureRequestIdentity(Request $request): void
    {
        if ($request->filled('client_request_id')) return;
        $candidate = trim((string) $request->header('Idempotency-Key'));
        $request->merge([
            'client_request_id' => Str::isUuid($candidate) ? $candidate : (string) Str::uuid(),
        ]);
    }

    private function validateContextOwnership(array $validated, ?int $userId): void
    {
        if (!empty($validated['lesson_id']) && !empty($validated['course_id'])) {
            abort_unless(
                Lesson::query()->whereKey($validated['lesson_id'])
                    ->where('list_id', $validated['course_id'])->exists(),
                422
            );
        }
        if (!empty($validated['order_id'])) {
            abort_unless(
                $userId && Order::query()->whereKey($validated['order_id'])
                    ->where('user_id', $userId)->exists(),
                422
            );
        }
    }

    private function requestFingerprint(array $validated, Request $request): string
    {
        return hash('sha256', json_encode([
            'category' => $validated['category'],
            'message' => trim($validated['message']),
            'screen_key' => $validated['screen_key'] ?? null,
            'course_id' => $validated['course_id'] ?? null,
            'lesson_id' => $validated['lesson_id'] ?? null,
            'order_id' => $validated['order_id'] ?? null,
            'requester_email' => strtolower(trim((string) ($validated['requester_email'] ?? ''))),
            'screenshot' => $this->uploadFingerprint($request->file('screenshot')),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array{platform:?string,app_version:?string,build_number:?int} */
    private function trustedClientRelease(Request $request): array
    {
        $empty = ['platform' => null, 'app_version' => null, 'build_number' => null];
        $platform = strtolower(trim((string) $request->header('X-Rokn-Platform')));
        $version = trim((string) $request->header('X-Rokn-App-Version'));
        $build = filter_var(
            $request->header('X-Rokn-App-Build'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 2147483647]]
        );

        if (!in_array($platform, ['android', 'ios'], true)
            || preg_match('/\A\d{1,6}(?:\.\d{1,6}){1,3}(?:[-+][0-9A-Za-z.-]{1,16})?\z/', $version) !== 1
            || $build === false) {
            return $empty;
        }

        try {
            $buildColumn = $platform === 'ios' ? 'build_number' : 'version_code';
            $known = DB::table('app_versions')
                ->where('platform', $platform)
                ->where('version_name', $version)
                ->where($buildColumn, (int) $build)
                ->exists();
        } catch (\Throwable) {
            return $empty;
        }

        return $known ? [
            'platform' => $platform,
            'app_version' => $version,
            'build_number' => (int) $build,
        ] : $empty;
    }

    private function uploadFingerprint(?\Illuminate\Http\UploadedFile $file): ?array
    {
        if (!$file) return null;
        $hash = hash_file('sha256', $file->getRealPath());
        abort_unless($hash && $file->getSize() > 0, 422, "تعذّرت قراءة الصورة\nاختر صورة أخرى");
        return ['sha256' => $hash, 'size' => (int) $file->getSize()];
    }
}
