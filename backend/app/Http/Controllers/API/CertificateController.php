<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Jobs\RecoverPendingCertificate;
use App\Models\Certificate;
use App\Models\Course;
use App\Services\ApiResponseService;
use App\Services\CertificateEligibilityService;
use App\Services\CertificateService;
use App\Support\DurableJobDispatch;
use App\Support\UnicodeText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CertificateController extends Controller
{
    public function __construct(
        private readonly CertificateService $certificates,
        private readonly CertificateEligibilityService $eligibility,
        private readonly ApiResponseService $responses
    ) {}

    /**
     * List all certificates for the authenticated user with course minimum details.
     */
    public function index(): JsonResponse
    {
        $user = auth('api')->user();

        if (! $user) {
            return $this->responses->error('سجّل الدخول أولًا', 401);
        }

        // Revoked credentials stay in the owner's contract even though the
        // app does not render them as usable certificates. Omitting them made
        // the learning screen mistake a terminal credential for a course that
        // had never been issued and offer a second, impossible issue action.
        $certificates = Certificate::where('user_id', $user->id)
            ->orderBy('generated_at', 'desc')
            ->orderByDesc('id')
            ->get()
            ->each(fn (Certificate $certificate) => $certificate->setRelation('user', $user)
            );

        return $this->responses->success(CertificateResource::collection($certificates), 'تم تحميل الشهادات');
    }

    /**
     * Read an already-issued credential without producing side effects (RFC
     * 9110 safe method). A pending artifact is rendered by the job the issue
     * POST queued; a lost job is re-queued by the issue POST itself and by the
     * scheduled certificates:recover-pending command, never by this read.
     */
    public function show($courseId): JsonResponse
    {
        $user = auth('api')->user();
        if (! $user) {
            return $this->responses->error('سجّل الدخول أولًا', 401);
        }

        $certificate = Certificate::query()
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->first();
        $certificate?->setRelation('user', $user);
        if (! $certificate) {
            return $this->responses->error('لم تصدر الشهادة بعد', 404, null, ['code' => 'certificate_not_issued']);
        }
        if (! $certificate->isActiveCredential()) {
            return $this->responses->error('هذه الشهادة ملغاة', 410, null, ['code' => 'certificate_revoked']);
        }
        if (! $certificate->hasCompleteCredentialSnapshot()) {
            return $this->invalidSnapshotResponse();
        }
        if (! $certificate->hasStoredArtifact()) {
            return $this->pendingResponse($certificate);
        }

        return $this->responses->success(new CertificateResource($certificate), 'تم تحميل الشهادة');
    }

    /** Issue a new certificate or recover its pending artifact. */
    public function issue(Request $request, $courseId): JsonResponse
    {
        $user = auth('api')->user();

        if (! $user) {
            return $this->responses->error('سجّل الدخول أولًا', 401);
        }

        $certificate = Certificate::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->first();
        $certificate?->setRelation('user', $user);

        if ($certificate) {
            if (! $certificate->isActiveCredential()) {
                return $this->responses->error('هذه الشهادة ملغاة', 410, null, ['code' => 'certificate_revoked']);
            }
            if (! $certificate->hasCompleteCredentialSnapshot()) {
                return $this->invalidSnapshotResponse();
            }
            if (! $certificate->hasStoredArtifact()) {
                $this->queueGeneration($certificate);

                return $this->pendingResponse($certificate);
            }

            return $this->responses->success(new CertificateResource($certificate), 'تم تحميل الشهادة');
        }

        $course = Course::find($courseId);
        if (! $course) {
            return $this->responses->error('الكورس غير متاح', 404);
        }

        $eligibility = $this->eligibility->for($user, $course);
        if ($eligibility['reason'] === 'entitlement_inactive') {
            return $this->responses->error('هذا الكورس غير مضاف إلى حسابك', 403);
        }
        if (! $eligibility['included']) {
            return $this->responses->error('الشهادة غير متاحة ضمن اشتراكك الحالي', 402, [
                'learning_access' => true,
                'certificate_available' => false,
                'upgrade_endpoint' => "/api/v1/courses/{$courseId}/full-track-upgrade",
            ], ['code' => 'certificate_upgrade_required']);
        }
        if ($eligibility['reason'] === 'financial_review') {
            return $this->responses->error("إصدار الشهادة متوقف مؤقتًا\nنعالج عملية الدفع المرتبطة بها", 409, null, ['code' => 'certificate_financial_review_required']);
        }
        if ($eligibility['reason'] === 'course_unavailable') {
            return $this->responses->error('الكورس غير متاح', 404);
        }
        if (! $eligibility['available']) {
            return $this->responses->error("أكمل المطلوب أولًا\nثم اطلب الشهادة", 403, null, ['code' => 'certificate_'.$eligibility['reason']]);
        }

        $request->validate([
            'holder_name' => ['required', 'string', 'max:120'],
        ]);
        $holderName = UnicodeText::clean($request->input('holder_name'), false);
        if (UnicodeText::graphemeLength($holderName) < 2) {
            return $this->responses->error('اكتب الاسم الذي سيظهر على الشهادة', 422, null, ['code' => 'certificate_holder_name_invalid']);
        }

        $certificate = $this->certificates->generate(
            $user,
            $course,
            $holderName,
            false
        );

        if (! $certificate) {
            // The immutable credential row is committed before rendering its
            // image. A renderer/storage interruption is therefore pending
            // work, not a failed issue action, and a retry must reuse it.
            $pending = Certificate::query()
                ->where('user_id', $user->id)
                ->where('course_id', $courseId)
                ->where('status', 'active')
                ->whereNull('revoked_at')
                ->whereNotNull('public_id')
                ->whereNotNull('holder_name')
                ->whereNotNull('course_name')
                ->whereNotNull('certificate_text_template_key')
                ->whereNotNull('certificate_text')
                ->first();
            if ($pending && ! $pending->hasStoredArtifact()) {
                $this->queueGeneration($pending);

                return $this->pendingResponse($pending);
            }

            return $this->responses->error("تعذّر إصدار الشهادة الآن\nحاول مرة أخرى", 500);
        }

        if (! $certificate->hasStoredArtifact()) {
            $this->queueGeneration($certificate);

            return $this->pendingResponse($certificate);
        }

        return $this->responses->success(new CertificateResource($certificate), 'تم إصدار الشهادة');
    }

    private function queueGeneration(Certificate $certificate): void
    {
        try {
            DurableJobDispatch::afterCommit(
                new RecoverPendingCertificate((int) $certificate->id)
            );
        } catch (\Throwable $exception) {
            // The pending row is the durable recovery marker. The scheduled
            // recovery command will enqueue it after a transient queue outage,
            // so an already accepted issue action must not become a false 500.
            report($exception);
        }
    }

    private function invalidSnapshotResponse(): JsonResponse
    {
        return $this->responses->error("تعذّر تحميل الشهادة الآن\nتواصل مع الدعم", 409, null, ['code' => 'certificate_snapshot_invalid']);
    }

    /**
     * A stable non-null contract lets mobile distinguish accepted background
     * work from a failed issue request and poll the read endpoint safely.
     */
    private function pendingResponse(Certificate $certificate): JsonResponse
    {
        $courseId = (int) $certificate->course_id;

        return $this->responses->success([
            'public_id' => (string) $certificate->public_id,
            'course_id' => $courseId,
            'status' => 'generating',
            'ready' => false,
            'poll_after_seconds' => 3,
            'status_endpoint' => "/api/v1/certificates/{$courseId}",
        ], 'نجهّز شهادتك الآن', 202, ['code' => 'certificate_generating']);
    }
}
