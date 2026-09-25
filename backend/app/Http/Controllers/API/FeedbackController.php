<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\FeedbackReport;
use App\Models\FeedbackAttachment;
use App\Services\SupportCaseAttachmentDeliveryService;
use App\Services\ApiResponseService;
use App\Services\SupportCaseService;
use App\Services\SupportCaseAccessService;
use App\Services\SupportCaseReadService;
use App\Services\SupportCaseSubmissionService;
use App\Support\PrivacyFingerprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Http\Response;

final class FeedbackController extends Controller
{
    public function __construct(
        private readonly SupportCaseAccessService $access,
        private readonly SupportCaseReadService $reads
    ) {
    }

    public function index(Request $request, ApiResponseService $responses): JsonResponse
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
                ->map(fn (FeedbackReport $report) => $this->reads->customerPayload($report))->all(),
            'pagination' => [
                'current_page' => $reports->currentPage(),
                'last_page' => $reports->lastPage(),
                'has_more' => $reports->hasMorePages(),
            ],
        ], 'تم تحميل الحالات');
    }

    public function store(
        Request $request,
        SupportCaseSubmissionService $submissions,
        ApiResponseService $responses
    ): JsonResponse {
        $this->ensureRequestIdentity($request);
        $validated = $request->validate($this->writeRules());
        $result = $submissions->submit(
            $validated,
            auth('api')->user(),
            $request->file('screenshot'),
            $this->trustedClientRelease($request) + [
                'request_id' => PrivacyFingerprint::make($request->header('X-Request-Id')),
                'ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
                'user_agent' => PrivacyFingerprint::make($request->userAgent()),
            ]
        );

        return $this->receipt($result->report, $responses, $result->replayed, $result->accessToken);
    }

    public function show(
        Request $request,
        string $publicId,
        ApiResponseService $responses
    ): JsonResponse {
        $report = FeedbackReport::query()->where('public_id', $publicId)->firstOrFail();
        $this->access->authorizeViewer($report, auth('api')->user(), $this->accessTokenFromRequest($request));
        return $responses->success($this->reads->customerPayload($report), 'تم تحميل الحالة');
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
        $this->access->authorizeViewer($report, $user, $this->accessTokenFromRequest($request));
        $cases->appendLearnerMessage(
            $report, $user, trim($validated['message']), $validated['client_request_id'], $request->file('screenshot')
        );
        return $responses->success($this->reads->customerPayload($report->fresh()), 'تم إرسال الرد');
    }

    public function attachment(
        string $publicId,
        int $attachment,
        SupportCaseAttachmentDeliveryService $delivery
    ): Response {
        $report = FeedbackReport::query()->where('public_id', $publicId)->firstOrFail();
        $file = FeedbackAttachment::query()
            ->whereKey($attachment)
            ->where('feedback_report_id', $report->id)
            ->where('scan_status', 'sanitized')
            ->firstOrFail();
        $bytes = $delivery->bytes($file);
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
        $report = $cases->claim($report, $user, $this->accessTokenFromRequest($request));
        return $responses->success($this->reads->customerPayload($report->fresh()), 'أضيف البلاغ إلى حسابك');
    }

    private function receipt(
        FeedbackReport $report,
        ApiResponseService $responses,
        bool $replayed,
        ?string $accessToken
    ): JsonResponse {
        $payload = $this->reads->customerPayload($report) + ['replayed' => $replayed];
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

    private function accessTokenFromRequest(Request $request): ?string
    {
        $token = trim((string) $request->header('X-Support-Access'));
        return $token !== '' && strlen($token) <= 128 ? $token : null;
    }
}
