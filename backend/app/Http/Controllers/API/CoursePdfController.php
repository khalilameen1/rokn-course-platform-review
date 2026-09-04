<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CoursePdf;
use App\Models\User;
use App\Services\CourseAttachmentService;
use App\Services\CourseModuleAccessService;
use App\Services\CourseStagedAuthoringService;
use App\Support\ResumableDownloadResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CoursePdfController extends Controller
{
    public function __construct(
        private CourseModuleAccessService $access,
        private CourseAttachmentService $attachments,
        private CourseStagedAuthoringService $revisions
    ) {}

    /** Get all active PDFs for an actively enrolled user. */
    public function index(int|string $courseId): JsonResponse
    {
        $user = auth('api')->user();
        if (!$user) {
            return $this->error('سجّل الدخول أولًا', 401);
        }

        $course = Course::find($courseId);
        if (!$course) {
            return $this->error('الكورس غير متاح', 404);
        }
        $course = $this->revisions->canonicalFor($course);
        if (!$this->access->hasCourseAccess($user, $course)) {
            return $this->error('هذا الكورس غير مضاف إلى حسابك', 403);
        }

        $pdfs = $course->activePdfs()->get()->map(function (CoursePdf $pdf) use ($course, $user): array {
            return $this->metadata($pdf, $course, $user);
        });

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل مرفقات الكورس',
            'data' => $pdfs,
        ]);
    }

    /** Get entitled PDF metadata without exposing a storage key. */
    public function show(int|string $courseId, int|string $pdfId): JsonResponse
    {
        $user = auth('api')->user();
        if (!$user) {
            return $this->error('سجّل الدخول أولًا', 401);
        }
        $course = Course::query()->find($courseId);
        if (!$course) {
            return $this->error('الكورس غير متاح', 404);
        }
        $course = $this->revisions->canonicalFor($course);
        if (!$this->access->hasCourseAccess($user, $course)) {
            return $this->error('هذا الكورس غير مضاف إلى حسابك', 403);
        }

        $currentPdfId = $this->revisions->currentEntityId(CoursePdf::class, (int) $pdfId)
            ?? (int) $pdfId;
        $pdf = CoursePdf::query()
            ->whereKey($currentPdfId)
            ->where('course_id', $course->id)
            ->where('is_active', true)
            ->first();
        if (!$pdf) {
            return $this->error('المرفق غير متاح', 404);
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل مرفق الكورس',
            'data' => $this->metadata($pdf, $course, $user),
        ]);
    }

    /** Download a course file from native/system clients without a bearer header. */
    public function download(
        Request $request,
        int|string $course,
        int|string $pdf
    ): Response
    {
        $user = $this->access->userFromOwnerClaim($request->query('owner'));
        abort_unless($user, 403);

        $courseModel = $this->revisions->canonicalFor(Course::query()->findOrFail($course));
        $pdf = $this->revisions->currentEntityId(CoursePdf::class, (int) $pdf) ?? (int) $pdf;
        $pdfModel = CoursePdf::query()
            ->whereKey($pdf)
            ->where('course_id', $courseModel->id)
            ->firstOrFail();
        abort_unless($this->access->canDownloadPdf($user, $courseModel, $pdfModel), 403);

        $file = $this->attachments->pdfFile($pdfModel);
        abort_unless($file !== null, 404);

        return ResumableDownloadResponse::make($file);
    }

    /** @return array<string, mixed> */
    private function metadata(CoursePdf $pdf, Course $course, User $user): array
    {
        return $this->attachments->pdfPayload($user, $course, $pdf);
    }

    private function error(string $message, int $httpStatus): JsonResponse
    {
        return response()->json([
            'status' => $httpStatus,
            'success' => false,
            'message' => $message,
            'data' => null,
        ], $httpStatus);
    }
}
