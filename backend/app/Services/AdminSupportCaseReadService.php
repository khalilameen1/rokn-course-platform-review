<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FeedbackAttachment;
use App\Models\FeedbackReport;
use App\Models\SupportCaseMessage;
use App\Models\User;
use App\Support\AdminStudentSearch;
use App\Support\BusinessClock;
use App\Support\CsvCell;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Shared read projection for the support inbox, case detail and filtered export. */
final readonly class AdminSupportCaseReadService
{
    public function __construct(private SupportCaseCompensationService $compensation) {}

    public function index(array $filters): array
    {
        return ['reports' => $this->query($filters)->latest('updated_at')->latest('id')->paginate(30)->withQueryString(),
            'admins' => $this->assignees()];
    }

    /** @return Collection<int, User> */
    public function assignees(): Collection
    {
        return User::query()->where('role', 'admin')->orderBy('name')->get(['id', 'name']);
    }

    /** @return Builder<FeedbackReport> */
    public function query(array $filters): Builder
    {
        $from = isset($filters['from']) ? BusinessClock::localDayRangeUtc($filters['from'])[0] : null;
        $toExclusive = isset($filters['to']) ? BusinessClock::localDayRangeUtc($filters['to'])[1] : null;
        $queryText = trim((string) ($filters['q'] ?? ''));

        $query = FeedbackReport::query()
            ->with(['user:id,name,email', 'course:id,name_ar,name_en', 'assignee:id,name'])
            ->when($queryText !== '', function ($query) use ($queryText): void {
                $escaped = '%'.AdminStudentSearch::escape($queryText).'%';
                $query->where(function ($nested) use ($escaped): void {
                    $nested->whereRaw("public_id LIKE ? ESCAPE '!'", [$escaped])
                        ->orWhereRaw("message LIKE ? ESCAPE '!'", [$escaped])
                        ->orWhereRaw("requester_email LIKE ? ESCAPE '!'", [$escaped])
                        ->orWhereHas('user', fn ($user) => $user
                            ->whereRaw("name LIKE ? ESCAPE '!'", [$escaped])
                            ->orWhereRaw("email LIKE ? ESCAPE '!'", [$escaped]));
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

        return $query;
    }

    public function show(FeedbackReport $feedback): array
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
                $available = $attachment->getAttribute('scan_status') === 'sanitized';
                if ($available) {
                    try {
                        $available = Storage::disk($attachment->disk)->exists($attachment->path);
                    } catch (\Throwable) {
                        $available = false;
                    }
                }
                $attachment->setAttribute('is_available', $available);
            });
        $admins = $this->assignees();
        $compensableCoins = (int) $feedback->user_id === (int) $feedback->order?->user_id
            ? $this->compensation->remainingCompensableCoins($feedback->order)
            : 0;
        $portfolioOwnerId = filter_var($feedback->context['portfolio_owner_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $portfolioOwner = $feedback->screen_key === 'public_portfolio_report' && $portfolioOwnerId !== false
            ? User::query()->find($portfolioOwnerId)
            : null;

        return compact('feedback', 'admins', 'compensableCoins', 'portfolioOwner');
    }

    public function export(array $filters): StreamedResponse
    {
        $query = $this->query($filters);
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
                        $report->user?->email ?: $report->getAttribute('requester_email'),
                        $report->course?->title, $report->getAttribute('order_id'),
                        $report->assignee?->name,
                        $report->created_at?->toIso8601String(),
                        $report->updated_at?->toIso8601String(),
                    ]));
                }
            });
            fclose($file);
        }, 'support-cases-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
