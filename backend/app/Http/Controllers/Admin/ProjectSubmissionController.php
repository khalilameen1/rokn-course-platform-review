<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\ProjectSubmissionResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProjectReviewIndexRequest;
use App\Http\Requests\Admin\ProjectReviewRequest;
use App\Models\AiInputAttachment;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Services\ProjectAttachmentDownloadService;
use App\Services\ProjectSubmissionReviewService;
use App\Support\DownloadFilename;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProjectSubmissionController extends Controller
{
    public function index(ProjectReviewIndexRequest $request): RedirectResponse
    {
        return redirect()->to(ProjectSubmissionResource::listUrl($request->validated()));
    }

    public function show(ProjectSubmission $projectSubmission): RedirectResponse
    {
        return redirect()->to(ProjectSubmissionResource::getUrl('view', ['record' => $projectSubmission]));
    }

    public function download(
        ProjectSubmission $projectSubmission,
        ProjectAttachmentDownloadService $downloads
    ): StreamedResponse {
        $file = $downloads->submissionForAdmin($projectSubmission);
        abort_if($file === null, 404);

        return $file['disk']->download($file['path'], $file['name'], [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => DownloadFilename::disposition($file['name']),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function downloadAttachment(
        ProjectSubmission $projectSubmission,
        AiInputAttachment $attachment,
        ProjectAttachmentDownloadService $downloads
    ): StreamedResponse {
        $file = $downloads->attachmentForAdmin($projectSubmission, $attachment);
        abort_if($file === null, 404);
        Log::info('Dashboard reviewer downloaded learner project artifact.', [
            'reviewer_id' => auth()->id(),
            'reviewer_role' => strtolower((string) auth()->user()?->role),
            'submission_id' => $projectSubmission->id,
            'attachment_id' => $attachment->id,
            'owner_type' => $attachment->owner_type,
        ]);

        return $file['disk']->download($file['path'], $file['name'], [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => DownloadFilename::disposition($file['name']),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function pass(
        ProjectReviewRequest $request,
        ProjectSubmission $projectSubmission,
        ProjectSubmissionReviewService $reviews
    ): RedirectResponse {
        $data = $request->validated();

        $reviews->reviewByStaff(
            $projectSubmission,
            $this->reviewer($request),
            true,
            $data['feedback'] ?? null
        );

        return redirect()
            ->route('admin.project-submissions.show', $projectSubmission)
            ->with('success', 'تم قبول محاولة المشروع وتسجيل قرار المراجع.');
    }

    public function reject(
        ProjectReviewRequest $request,
        ProjectSubmission $projectSubmission,
        ProjectSubmissionReviewService $reviews
    ): RedirectResponse {
        $data = $request->validated();

        $reviews->reviewByStaff(
            $projectSubmission,
            $this->reviewer($request),
            false,
            $data['feedback']
        );

        return redirect()
            ->route('admin.project-submissions.show', $projectSubmission)
            ->with('success', 'تم طلب إعادة إرسال محاولة المشروع وتسجيل قرار المراجع.');
    }

    private function reviewer(Request $request): User
    {
        $reviewer = $request->user();
        abort_unless($reviewer instanceof User, 403);

        return $reviewer;
    }
}
