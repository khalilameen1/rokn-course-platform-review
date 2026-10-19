<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SupportCaseCompensationRequest;
use App\Http\Requests\Admin\SupportCaseIndexRequest;
use App\Http\Requests\Admin\SupportCaseMessageRequest;
use App\Http\Requests\Admin\SupportCaseStateRequest;
use App\Models\FeedbackAttachment;
use App\Models\FeedbackReport;
use App\Models\SupportCaseMessage;
use App\Models\User;
use App\Services\SupportCaseAttachmentDeliveryService;
use App\Services\SupportCaseCompensationService;
use App\Services\SupportCaseService;
use App\Support\BusinessClock;
use App\Support\CsvCell;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FeedbackController extends Controller
{
    public function index(SupportCaseIndexRequest $request): View
    {
        [$filters, $query] = $this->filteredReports($request);
        $reports = $query->latest('updated_at')->latest('id')->paginate(30)->withQueryString();
        $admins = User::query()->where('role', 'admin')->orderBy('name')->get(['id', 'name']);

        return view('admin.feedback.index', compact('reports', 'filters', 'admins'));
    }

    /** @return array{array<string, mixed>, Builder<FeedbackReport>} */
    private function filteredReports(SupportCaseIndexRequest $request): array
    {
        $filters = $request->validated();
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

    public function show(FeedbackReport $feedback, SupportCaseCompensationService $compensation): View
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
        $compensableCoins = (int) $feedback->user_id === (int) $feedback->order?->user_id
            ? $compensation->remainingCompensableCoins($feedback->order)
            : 0;

        return view('admin.feedback.show', compact('feedback', 'admins', 'compensableCoins'));
    }

    public function export(SupportCaseIndexRequest $request): StreamedResponse
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
        SupportCaseStateRequest $request,
        FeedbackReport $feedback,
        SupportCaseService $cases
    ): RedirectResponse {
        $validated = $request->validated();
        $cases->updateState($feedback, $validated, $request->user()?->id);

        return back()->with('success', 'تم تحديث الحالة');
    }

    public function message(
        SupportCaseMessageRequest $request,
        FeedbackReport $feedback,
        SupportCaseService $cases
    ): RedirectResponse {
        $validated = $request->validated();
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
        SupportCaseCompensationRequest $request,
        FeedbackReport $feedback,
        SupportCaseCompensationService $compensation
    ): RedirectResponse {
        $validated = $request->validated();
        if (! $feedback->order_id) {
            return back()->with('error', 'اربط البلاغ بطلب موثّق أولًا');
        }
        try {
            $compensation->compensate($feedback, $validated, $request->user()?->id);
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return back()->withInput()->with('error', "لا يمكن إضافة هذا التعويض\nتحقق من أن المبلغ لا يتجاوز الحد المتاح");
        }

        return back()->with('success', 'أُضيفت العملات إلى محفظة الطالب وربط التعويض بالبلاغ');
    }

    public function attachment(
        FeedbackReport $feedback,
        FeedbackAttachment $attachment,
        SupportCaseAttachmentDeliveryService $delivery
    ): Response {
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
}
