<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiInputAttachment;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Services\AdminProjectSubmissionReviewReadService;
use App\Services\ProjectAttachmentDownloadService;
use App\Services\ProjectSubmissionService;
use App\Support\DownloadFilename;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProjectSubmissionController extends Controller
{
    public function __construct(
        private readonly AdminProjectSubmissionReviewReadService $reviewReads
    ) {
    }

    public function index(Request $request): View
    {
        $isAdministrator = $this->isAdministrator($request);
        $filters = $request->validate([
            'status' => 'nullable|in:pending,passed,needs_resubmission',
            'search' => 'nullable|string|max:100',
        ]);

        $page = $this->reviewReads->index($filters, $isAdministrator);
        $submissions = $page['submissions'];
        $statusCounts = $page['statusCounts'];

        return view('admin.project-submissions.index', compact(
            'submissions',
            'statusCounts',
            'filters',
            'isAdministrator'
        ));
    }

    public function show(Request $request, ProjectSubmission $projectSubmission): View
    {
        return view('admin.project-submissions.show', [
            ...$this->reviewReads->show($projectSubmission, $this->isAdministrator($request)),
            'isAdministrator' => $this->isAdministrator($request),
        ]);
    }

    public function download(
        ProjectSubmission $projectSubmission,
        ProjectAttachmentDownloadService $downloads
    ): StreamedResponse
    {
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
        Request $request,
        ProjectSubmission $projectSubmission,
        ProjectSubmissionService $submissionService
    ): RedirectResponse {
        $data = $request->validate([
            'feedback' => 'nullable|string|max:2000',
        ]);

        $submissionService->reviewByStaff(
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
        Request $request,
        ProjectSubmission $projectSubmission,
        ProjectSubmissionService $submissionService
    ): RedirectResponse {
        $data = $request->validate([
            'feedback' => 'required|string|min:3|max:2000',
        ]);

        $submissionService->reviewByStaff(
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

    private function isAdministrator(Request $request): bool
    {
        return strtolower((string) $request->user()?->role) === 'admin';
    }
}
