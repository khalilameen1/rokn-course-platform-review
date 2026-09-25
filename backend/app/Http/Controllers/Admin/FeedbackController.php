<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeedbackAttachment;
use App\Services\SupportCaseAttachmentDeliveryService;
use App\Models\FeedbackReport;
use App\Models\SupportCaseMessage;
use App\Models\User;
use App\Services\SupportCaseCompensationService;
use App\Services\SupportCaseService;
use App\Support\BusinessClock;
use App\Support\CsvCell;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FeedbackController extends Controller
{
    public function index(Request $request): View
    {
        [$filters, $query] = $this->filteredReports($request);
        $reports = $query->latest('updated_at')->latest('id')->paginate(30)->withQueryString();
        $admins = User::query()->where('role', 'admin')->orderBy('name')->get(['id', 'name']);

        return view('admin.feedback.index', compact('reports', 'filters', 'admins'));
    }

    /** @return array{array<string, mixed>, Builder<FeedbackReport>} */
    private function filteredReports(Request $request): array
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:120',
            'status' => ['nullable', Rule::in(SupportCaseService::CUSTOMER_STATUSES)],
            'category' => ['nullable', Rule::in(['bug', 'suggestion', 'course_content', 'playback'])],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'assigned_to' => 'nullable|integer|exists:users,id',
            'overdue' => 'nullable|boolean',
            'app_version' => 'nullable|string|max:32',
            'course_id' => 'nullable|integer',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);
        $from = isset($filters['from']) ? BusinessClock::localDayRangeUtc($filters['from'])[0] : null;
        $toExclusive = isset($filters['to']) ? BusinessClock::localDayRangeUtc($filters['to'])[1] : null;
        $queryText = trim((string) ($filters['q'] ?? ''));

        $query = FeedbackReport::query()
            ->with(['user:id,name,email', 'course:id,name_ar,name_en', 'assignee:id,name'])
            ->when($queryText !== '', function ($query) use ($queryText): void {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $queryText);
                $query->where(function ($nested) use ($escaped): void {
                    $nested->where('public_id', 'like', "%{$escaped}%")
                        ->orWhere('message', 'like', "%{$escaped}%")
                        ->orWhere('requester_email', 'like', "%{$escaped}%")
                        ->orWhereHas('user', fn ($user) => $user
                            ->where('name', 'like', "%{$escaped}%")
                            ->orWhere('email', 'like', "%{$escaped}%"));
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($filters['category'] ?? null, fn ($q, $value) => $q->where('category', $value))
            ->when($filters['priority'] ?? null, fn ($q, $value) => $q->where('priority', $value))
            ->when($filters['assigned_to'] ?? null, fn ($q, $value) => $q->where('assigned_to', $value))
            ->when(($filters['overdue'] ?? false), fn ($q) => $q
                ->whereNull('last_staff_message_at')->where('first_response_due_at', '<', now())
                ->whereNotIn('status', ['resolved', 'closed', 'dismissed']))
            ->when($filters['app_version'] ?? null, fn ($q, $value) => $q->where('app_version', $value))
            ->when($filters['course_id'] ?? null, fn ($q, $value) => $q->where('course_id', $value))
            ->when($from, fn ($q, $value) => $q->where('created_at', '>=', $value))
            ->when($toExclusive, fn ($q, $value) => $q->where('created_at', '<', $value));

        return [$filters, $query];
    }

    public function show(FeedbackReport $feedback): View
    {
        $feedback->load([
            'user', 'course', 'lesson', 'order', 'assignee', 'attachments',
            'messages' => fn ($query) => $query->with(['author:id,name', 'attachments'])->orderBy('id'),
            'events' => fn ($query) => $query->with('actor:id,name')->orderByDesc('id')->limit(100),
        ]);
        $feedback->attachments
            ->concat($feedback->messages->flatMap(fn (SupportCaseMessage $message) => $message->attachments))
            ->unique('id')
            ->each(function (FeedbackAttachment $attachment): void {
            $available = $attachment->scan_status === 'sanitized';
            if ($available) {
                try {
                    $available = Storage::disk($attachment->disk)->exists($attachment->path);
                } catch (\Throwable) {
                    $available = false;
                }
            }
            $attachment->setAttribute('is_available', $available);
            });
        $admins = User::query()->where('role', 'admin')->orderBy('name')->get(['id', 'name']);
        return view('admin.feedback.show', compact('feedback', 'admins'));
    }

    public function export(Request $request): StreamedResponse
    {
        [, $query] = $this->filteredReports($request);
        $query->limit(10000);

        return response()->streamDownload(function () use ($query): void {
            $file = fopen('php://output', 'wb');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['case_number', 'status', 'category', 'priority', 'student', 'email', 'course', 'order_id', 'assignee', 'created_at', 'updated_at']);
            $query->reorder('id')->chunkById(500, function ($reports) use ($file): void {
                foreach ($reports as $report) {
                    fputcsv($file, CsvCell::row([
                        strtoupper(substr((string) $report->public_id, -8)),
                        $report->status, $report->category, $report->priority,
                        $report->user?->name ?: 'guest',
                        $report->user?->email ?: $report->requester_email,
                        $report->course?->title, $report->order_id,
                        $report->assignee?->name,
                        $report->created_at?->toIso8601String(),
                        $report->updated_at?->toIso8601String(),
                    ]));
                }
            });
            fclose($file);
        }, 'support-cases-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function update(
        Request $request,
        FeedbackReport $feedback,
        SupportCaseService $cases
    ): RedirectResponse {
        $validated = $request->validate([
            'version' => 'required|integer|min:1',
            'status' => ['required', Rule::in(SupportCaseService::CUSTOMER_STATUSES)],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'assigned_to' => ['nullable', Rule::exists('users', 'id')->where(fn ($q) => $q->where('role', 'admin'))],
            'resolution_kind' => ['nullable', Rule::in(['fixed', 'guidance', 'compensated', 'not_reproducible', 'duplicate'])],
        ]);
        $cases->updateState($feedback, $validated, $request->user()?->id);
        return back()->with('success', 'تم تحديث الحالة');
    }

    public function message(
        Request $request,
        FeedbackReport $feedback,
        SupportCaseService $cases
    ): RedirectResponse {
        $validated = $request->validate([
            'version' => 'required|integer|min:1',
            'client_request_id' => 'required|uuid',
            'visibility' => ['required', Rule::in(['customer', 'internal'])],
            'message' => 'required|string|min:2|max:4000',
        ]);
        $cases->appendStaffMessage(
            $feedback,
            $request->user(),
            trim($validated['message']),
            $validated['visibility'],
            $validated['client_request_id'],
            (int) $validated['version']
        );
        return back()->with('success', $validated['visibility'] === 'internal' ? 'تم حفظ الملاحظة الداخلية' : 'تم إرسال الرد');
    }

    public function compensate(
        Request $request,
        FeedbackReport $feedback,
        SupportCaseCompensationService $compensation
    ): RedirectResponse {
        $validated = $request->validate([
            'version' => 'required|integer|min:1',
            'amount' => 'required|integer|min:1|max:100000000',
            'note' => 'required|string|min:8|max:1000',
        ]);
        if (!$feedback->order_id) return back()->with('error', 'اربط البلاغ بطلب موثّق أولًا');
        try {
            $compensation->compensate($feedback, $validated, $request->user()?->id);
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return back()->with('error', 'لا يمكن تسجيل التعويض على هذا الطلب');
        }
        return back()->with('success', 'تم تسجيل التعويض وربطه بالبلاغ');
    }

    public function attachment(
        FeedbackReport $feedback,
        FeedbackAttachment $attachment,
        SupportCaseAttachmentDeliveryService $delivery
    ): Response
    {
        abort_unless((int) $attachment->feedback_report_id === (int) $feedback->id, 404);
        $bytes = $delivery->bytes($attachment);
        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename="'.$feedback->public_id.'.jpg"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function destroy(FeedbackReport $feedback): RedirectResponse
    {
        return back()->with('error', "أغلق البلاغ بدل حذفه\nيُزال تلقائيًا بعد مدة الاحتفاظ");
    }
}
