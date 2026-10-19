<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectSubmissionResource\Pages;

use App\Filament\Resources\ProjectSubmissionResource;
use App\Models\AiInputAttachment;
use App\Models\ProjectFeedbackMessage;
use App\Models\ProjectSubmission;
use App\Services\AdminProjectSubmissionReviewReadService;
use App\Services\ProjectSubmissionReviewService;
use App\Support\BusinessClock;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

/** @extends ViewRecord<ProjectSubmission> */
final class ViewProjectSubmission extends ViewRecord
{
    protected static string $resource = ProjectSubmissionResource::class;

    private ?array $details = null;

    public function getTitle(): string
    {
        return $this->getRecord()->project?->section?->title ?: 'مراجعة محاولة مشروع';
    }

    public function details(): array
    {
        return $this->details ??= app(AdminProjectSubmissionReviewReadService::class)->show($this->getRecord(), ProjectSubmissionResource::administrator());
    }

    public function canDecide(bool $passed): bool
    {
        $record = $this->getRecord();

        return ProjectSubmissionResource::allows($passed ? 'pass' : 'reject') && $this->details()['isLatestAttempt']
            && ($record->review_status === ProjectSubmission::STATUS_PENDING
                || ($passed && $record->review_status === ProjectSubmission::STATUS_PASSED && $record->review_source === 'graceful_fallback'));
    }

    public function files(): array
    {
        $record = $this->details()['submission'];
        if ($record->aiInputAttachments->isNotEmpty()) {
            return $record->aiInputAttachments->map(fn (AiInputAttachment $file): array => $this->attachment($file))->all();
        }

        return filled($record->submission_file)
            ? [['name' => $record->original_file_name ?: 'ملف المشروع', 'url' => route('admin.project-submissions.download', $record), 'details' => $record->mime_type]] : [];
    }

    public function conversation(): array
    {
        $states = ['queued' => 'في الانتظار', 'sent' => 'أُرسل', 'streaming' => 'يُكتب الآن', 'completed' => 'مكتمل', 'failed' => 'تعذّر', 'cancelled' => 'أُلغي'];

        return $this->details()['threadMessages']->map(function (ProjectFeedbackMessage $message) use ($states): array {
            $usage = ProjectSubmissionResource::administrator() && $message->relationLoaded('usageEvent') ? $message->usageEvent : null;

            return ['kind' => $message->role === 'user' ? 'الطالب' : 'رُكن', 'body' => $message->body,
                'state' => $states[$message->status] ?? 'غير معروف', 'at' => BusinessClock::format($message->created_at, 'Y-m-d H:i:s'),
                'attachments' => $message->getRelation('inputAttachments')->map(fn (AiInputAttachment $file): array => $this->attachment($file))->all(),
                'usage' => $usage ? $usage->status.' · '.number_format((int) $usage->total_tokens).' توكن · $'.number_format((float) $usage->cost_usd, 6) : null];
        })->all();
    }

    private function attachment(AiInputAttachment $file): array
    {
        return ['name' => $file->original_file_name, 'details' => $file->mime_type.' · '.number_format($file->size_bytes / 1024, 1).' KB',
            'url' => route('admin.project-submissions.attachments.download', [$this->getRecord(), $file])];
    }

    protected function getHeaderActions(): array
    {
        return array_map(fn (bool $passed): Action => Action::make($passed ? 'pass' : 'reject')
            ->label($passed ? 'قبول المحاولة' : 'طلب إعادة الإرسال')->color($passed ? 'success' : 'danger')
            ->authorize(fn (): bool => ProjectSubmissionResource::allows($passed ? 'pass' : 'reject'))
            ->visible(fn (): bool => $this->canDecide($passed))->requiresConfirmation()
            ->modalDescription($passed ? 'يُسجّل قبولك باسمك ويُحدّث تقدم الطالب' : 'يظهر السبب للطالب لإعادة تنفيذ المشروع')
            ->schema([Textarea::make('feedback')->label($passed ? 'ملاحظة القبول' : 'سبب طلب إعادة الإرسال')
                ->required(! $passed)->minLength($passed ? 0 : 3)->maxLength(2000)->rows(4)])
            ->modalSubmitActionLabel($passed ? 'تأكيد القبول' : 'طلب إعادة الإرسال')
            ->action(fn (Action $action, array $data) => $this->decide($action, $passed, $data)), [true, false]);
    }

    private function decide(Action $action, bool $passed, array $data): void
    {
        abort_unless(ProjectSubmissionResource::allows($passed ? 'pass' : 'reject'), 403);
        try {
            app(ProjectSubmissionReviewService::class)->reviewByStaff($this->getRecord(), auth('web')->user(), $passed, $data['feedback'] ?? null);
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['submission'])) {
                Notification::make()->title($exception->errors()['submission'][0])->warning()->send();
                throw new Halt;
            }
            $path = $this->getMountedActionSchema(mountedAction: $action)?->getStatePath();
            if ($path === null) {
                throw $exception;
            }
            throw ValidationException::withMessages([$path.'.feedback' => $exception->errors()['feedback'] ?? ['راجع ملاحظة القرار']]);
        }
        $this->getRecord()->refresh();
        $this->details = null;
        Notification::make()->title('تم تسجيل قرار المراجعة')->success()->send();
    }
}
