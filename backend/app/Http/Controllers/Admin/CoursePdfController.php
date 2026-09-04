<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CoursePdfOrderRequest;
use App\Http\Requests\Admin\CoursePdfRequest;
use App\Http\Requests\Admin\CoursePdfVersionRequest;
use App\Models\Course;
use App\Models\CoursePdf;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminCoursePdfApplicationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class CoursePdfController extends Controller
{
    public function __construct(
        private readonly AdminCoursePdfApplicationService $pdfs,
        private readonly AdminAuthoringCreateIntentService $createIntents
    ) {
    }

    public function index(Course $course): Response
    {
        return redirect()->to($this->studioAttachmentsUrl($course));
    }

    public function create(Course $course): Response
    {
        return redirect()->to($this->studioAttachmentsUrl($course));
    }

    public function store(CoursePdfRequest $request, Course $course): Response
    {
        $data = $request->validated();
        $file = $request->file('pdf_file');
        if (!$file instanceof UploadedFile) {
            throw ValidationException::withMessages(['pdf_file' => 'اختر ملف PDF صالحًا']);
        }

        return $this->mutationResponse(
            $request,
            $course,
            fn (): array => $this->pdfs->store(
                $course,
                $file,
                $data,
                (int) $data['authoring_version'],
                (string) $data['authoring_request_id'],
                function (Course $lockedCourse, CoursePdf $pdf, array $payload) use ($request): void {
                    $this->completeStoreIntent($request, $lockedCourse, $pdf, $payload);
                }
            ),
            'تعذر رفع الملف الآن\nحاول مرة أخرى'
        );
    }

    public function edit(Course $course, CoursePdf $pdf): Response
    {
        $this->assertPdfBelongsToCourse($course, $pdf);

        return redirect()->to($this->studioAttachmentsUrl($course));
    }

    public function update(CoursePdfRequest $request, Course $course, CoursePdf $pdf): Response
    {
        $data = $request->validated();

        return $this->mutationResponse(
            $request,
            $course,
            fn (): array => $this->pdfs->update(
                $course,
                $pdf,
                $data,
                (int) $data['authoring_version'],
                $request->file('pdf_file')
            ),
            'تعذر تحديث الملف الآن\nحاول مرة أخرى'
        );
    }

    public function destroy(
        CoursePdfVersionRequest $request,
        Course $course,
        CoursePdf $pdf
    ): Response {
        return $this->mutationResponse(
            $request,
            $course,
            fn (): array => $this->pdfs->destroy(
                $course,
                $pdf,
                (int) $request->validated('authoring_version')
            ),
            'تعذر حذف الملف الآن'
        );
    }

    public function reorder(CoursePdfOrderRequest $request, Course $course): Response
    {
        $data = $request->validated();

        return $this->mutationResponse(
            $request,
            $course,
            fn (): array => $this->pdfs->reorder(
                $course,
                array_map('intval', $data['order']),
                (int) $data['authoring_version']
            ),
            'تعذر تحديث الترتيب الآن',
            true
        );
    }

    public function toggleStatus(
        CoursePdfVersionRequest $request,
        Course $course,
        CoursePdf $pdf
    ): Response {
        return $this->mutationResponse(
            $request,
            $course,
            fn (): array => $this->pdfs->toggle(
                $course,
                $pdf,
                (int) $request->validated('authoring_version')
            ),
            'تعذر تحديث حالة الملف الآن',
            true
        );
    }

    public function preview(Course $course, CoursePdf $pdf): Response
    {
        $this->assertPdfBelongsToCourse($course, $pdf);
        if (!$pdf->fileExists()) {
            abort(404, 'الملف غير موجود');
        }

        return Storage::disk($pdf->storage_disk)->response($pdf->file_path, 'document.pdf', [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    /** @param Closure(): array<string, mixed> $operation */
    private function mutationResponse(
        Request $request,
        Course $course,
        Closure $operation,
        string $failureMessage,
        bool $jsonOnly = false
    ): Response {
        try {
            $payload = $operation();
            if ($jsonOnly || $request->expectsJson()) {
                return response()->json($payload);
            }

            return redirect()
                ->to($this->studioAttachmentsUrl($course))
                ->with('success', (string) $payload['message']);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            if ($jsonOnly || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $failureMessage,
                ], 500);
            }

            return redirect()->back()->withInput()->with('error', $failureMessage);
        }
    }

    /** @param array<string, mixed> $payload */
    private function completeStoreIntent(
        Request $request,
        Course $course,
        CoursePdf $pdf,
        array $payload
    ): void {
        if ($request->expectsJson()) {
            $this->createIntents->completeJson($request, $payload, 200, CoursePdf::class, $pdf->id);
            return;
        }

        $this->createIntents->completeRedirect(
            $request,
            $this->studioAttachmentsUrl($course),
            302,
            CoursePdf::class,
            $pdf->id
        );
    }

    private function studioAttachmentsUrl(Course $course): string
    {
        return route('admin.courses.show', $course).'#studioCourseAttachments';
    }

    private function assertPdfBelongsToCourse(Course $course, CoursePdf $pdf): void
    {
        abort_unless((int) $pdf->course_id === (int) $course->id, 404);
    }
}
