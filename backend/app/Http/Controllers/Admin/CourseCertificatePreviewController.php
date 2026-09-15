<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Course;
use App\Services\CertificateArtworkRenderer;
use App\Services\CertificateIssuanceSnapshotService;
use App\Services\CourseStagedAuthoringService;
use App\Support\UnicodeText;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class CourseCertificatePreviewController extends Controller
{
    public function __invoke(
        Request $request,
        Course $course,
        CourseStagedAuthoringService $authoring,
        CertificateIssuanceSnapshotService $snapshots,
        CertificateArtworkRenderer $renderer
    ): Response {
        $request->validate(['image' => ['sometimes', 'boolean']]);

        // A GET can read an existing draft, never create one or issue a credential.
        $previewCourse = $authoring->activeDraftFor($course) ?: $course;
        $snapshot = $snapshots->forPreview($previewCourse);
        $courseName = UnicodeText::limit(UnicodeText::clean($previewCourse->name_ar, false), 255);
        $hasPassageProjects = $snapshot['certificate_completion_text'] !== '';

        if (!$request->boolean('image')) {
            return response()->view('admin.courses.certificate-preview', [
                'previewCourse' => $previewCourse,
                'courseName' => $courseName,
                'hasPassageProjects' => $hasPassageProjects,
            ])->header('Cache-Control', 'private, no-store, max-age=0');
        }

        $certificate = new Certificate();
        $certificate->forceFill([
            'public_id' => '00000000-0000-0000-0000-000000000000',
            'course_id' => $previewCourse->id,
            'holder_name' => 'اسم المتعلم',
            'course_name' => $courseName !== '' ? $courseName : 'اسم الكورس',
            'certificate_text_template_key' => $previewCourse->certificate_text_template_key,
            'certificate_design_version' => $snapshot['certificate_design_version'],
            'certificate_text' => $snapshot['certificate_text'],
            'certificate_completion_text' => $snapshot['certificate_completion_text'],
            'generated_at' => now(),
        ]);
        $png = $renderer->render($certificate, [
            // A sample must not link to a real learner or a valid credential.
            'url' => 'https://preview.invalid/certificate',
            'type' => 'certificate',
            'title' => 'التحقق من الشهادة',
            'hint' => 'امسح الرمز للتحقق',
        ]);

        return response($png)->withHeaders([
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="rokn-certificate-preview.png"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }
}
