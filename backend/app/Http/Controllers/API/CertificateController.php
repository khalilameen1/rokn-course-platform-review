<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Jobs\RecoverPendingCertificate;
use App\Models\Certificate;
use App\Models\Course;
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
        private readonly CertificateEligibilityService $eligibility
    ) {
    }

    /**
     * List all certificates for the authenticated user with course minimum details.
     */
    public function index(): JsonResponse
    {
        $user = auth('api')->user();

        if (!$user) {
            return response()->json([
                'status'  => 401,
                'success' => false,
                'message' => 'سجّل الدخول أولًا',
                'data' => null,
            ], 401);
        }

        // Revoked credentials stay in the owner's contract even though the
        // app does not render them as usable certificates. Omitting them made
        // the learning screen mistake a terminal credential for a course that
        // had never been issued and offer a second, impossible issue action.
        $certificates = Certificate::where('user_id', $user->id)
            ->orderBy('generated_at', 'desc')
            ->orderByDesc('id')
            ->get()
            ->each(fn (Certificate $certificate) =>
                $certificate->setRelation('user', $user)
            );

        return response()->json([
            'status'  => 200,
            'success' => true,
            'message' => 'تم تحميل الشهادات',
            'data'    => CertificateResource::collection($certificates),
        ]);
    }

    /** Read an already-issued credential without producing side effects. */
    public function show($courseId): JsonResponse
    {
        $user = auth('api')->user();
        if (!$user) {
            return response()->json([
                'status' => 401,
                'success' => false,
                'message' => 'سجّل الدخول أولًا',
                'data' => null,
            ], 401);
        }

        $certificate = Certificate::query()
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->first();
        $certificate?->setRelation('user', $user);
        if (!$certificate) {
            return response()->json([
                'status' => 404,
                'success' => false,
                'code' => 'certificate_not_issued',
                'message' => 'لم تصدر الشهادة بعد',
                'data' => null,
            ], 404);
        }
        if (!$certificate->isActiveCredential()) {
            return response()->json([
                'status' => 410,
                'success' => false,
                'code' => 'certificate_revoked',
                'message' => 'هذه الشهادة ملغاة',
                'data' => null,
            ], 410);
        }
        if (!$certificate->hasCompleteCredentialSnapshot()) {
            return $this->invalidSnapshotResponse();
        }
        if (!$certificate->hasStoredArtifact()) {
            $this->queueGeneration($certificate);

            return $this->pendingResponse($certificate);
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل الشهادة',
            'data' => new CertificateResource($certificate),
        ]);
    }

    /** Issue a new certificate or recover its pending artifact. */
    public function issue(Request $request, $courseId): JsonResponse
    {
        $user = auth('api')->user();

        if (!$user) {
            return response()->json([
                'status'  => 401,
                'success' => false,
                'message' => 'سجّل الدخول أولًا',
                'data' => null,
            ], 401);
        }

        $certificate = Certificate::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->first();
        $certificate?->setRelation('user', $user);

        if ($certificate) {
            if (!$certificate->isActiveCredential()) {
                return response()->json([
                    'status' => 410,
                    'success' => false,
                    'code' => 'certificate_revoked',
                    'message' => 'هذه الشهادة ملغاة',
                    'data' => null,
                ], 410);
            }
            if (!$certificate->hasCompleteCredentialSnapshot()) {
                return $this->invalidSnapshotResponse();
            }
            if (!$certificate->hasStoredArtifact()) {
                $this->queueGeneration($certificate);

                return $this->pendingResponse($certificate);
            }

            return response()->json([
                'status'  => 200,
                'success' => true,
                'message' => 'تم تحميل الشهادة',
                'data'    => new CertificateResource($certificate),
            ]);
        }

        $course = Course::find($courseId);
        if (!$course) {
            return response()->json([
                'status'  => 404,
                'success' => false,
                'message' => 'الكورس غير متاح',
                'data' => null,
            ], 404);
        }

        $eligibility = $this->eligibility->for($user, $course);
        if ($eligibility['reason'] === 'entitlement_inactive') {
            return response()->json([
                'status'  => 403,
                'success' => false,
                'message' => 'هذا الكورس غير مضاف إلى حسابك',
                'data' => null,
            ], 403);
        }
        if (!$eligibility['included']) {
            return response()->json([
                'status' => 402,
                'success' => false,
                'code' => 'certificate_upgrade_required',
                'message' => "المنحة تشمل الكورس والمشروعات\nالشهادة متاحة في الفئات المدفوعة",
                'data' => [
                    'learning_access' => true,
                    'certificate_available' => false,
                    'upgrade_endpoint' => "/api/v1/courses/{$courseId}/full-track-upgrade",
                ],
            ], 402);
        }
        if ($eligibility['reason'] === 'financial_review') {
            return response()->json([
                'status' => 409,
                'success' => false,
                'code' => 'certificate_financial_review_required',
                'message' => "إصدار الشهادة متوقف مؤقتًا\nنعالج عملية الدفع المرتبطة بها",
                'data' => null,
            ], 409);
        }
        if ($eligibility['reason'] === 'course_unavailable') {
            return response()->json([
                'status' => 404,
                'success' => false,
                'message' => 'الكورس غير متاح',
                'data' => null,
            ], 404);
        }
        if (!$eligibility['available']) {
            return response()->json([
                'status' => 403,
                'success' => false,
                'code' => 'certificate_' . $eligibility['reason'],
                'message' => "أكمل المطلوب أولًا\nثم اطلب الشهادة",
                'data' => null,
            ], 403);
        }

        $request->validate([
            'holder_name' => ['required', 'string', 'max:120'],
        ]);
        $holderName = UnicodeText::clean($request->input('holder_name'), false);
        if (UnicodeText::graphemeLength($holderName) < 2) {
            return response()->json([
                'status' => 422,
                'success' => false,
                'code' => 'certificate_holder_name_invalid',
                'message' => 'اكتب الاسم الذي سيظهر على الشهادة',
                'data' => null,
            ], 422);
        }

        $certificate = $this->certificates->generate(
            $user,
            $course,
            $holderName,
            false
        );

        if (!$certificate) {
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
            if ($pending && !$pending->hasStoredArtifact()) {
                $this->queueGeneration($pending);

                return $this->pendingResponse($pending);
            }

            return response()->json([
                'status'  => 500,
                'success' => false,
                'message' => "تعذّر إصدار الشهادة الآن\nحاول مرة أخرى",
                'data' => null,
            ], 500);
        }

        if (!$certificate->hasStoredArtifact()) {
            $this->queueGeneration($certificate);

            return $this->pendingResponse($certificate);
        }

        return response()->json([
            'status'  => 200,
            'success' => true,
            'message' => 'تم إصدار الشهادة',
            'data'    => new CertificateResource($certificate),
        ]);
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
        return response()->json([
            'status' => 409,
            'success' => false,
            'code' => 'certificate_snapshot_invalid',
            'message' => "تعذّر تحميل الشهادة الآن\nتواصل مع الدعم",
            'data' => null,
        ], 409);
    }

    /**
     * A stable non-null contract lets mobile distinguish accepted background
     * work from a failed issue request and poll the read endpoint safely.
     */
    private function pendingResponse(Certificate $certificate): JsonResponse
    {
        $courseId = (int) $certificate->course_id;

        return response()->json([
            'status' => 202,
            'success' => true,
            'code' => 'certificate_generating',
            'message' => 'نجهّز شهادتك الآن',
            'data' => [
                'public_id' => (string) $certificate->public_id,
                'course_id' => $courseId,
                'status' => 'generating',
                'ready' => false,
                'poll_after_seconds' => 3,
                'status_endpoint' => "/api/v1/certificates/{$courseId}",
            ],
        ], 202);
    }

}
