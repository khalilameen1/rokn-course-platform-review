<?php

namespace App\Filament\Resources\CourseCodeResource\Pages;

use App\Filament\Resources\CourseCodeResource;
use App\Models\CourseCode;
use App\Services\AdminCourseCodeAuthoringService;
use App\Support\CourseCodeEditorVersion;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

final class ListCourseCodes extends ListRecords
{
    protected static string $resource = CourseCodeResource::class;

    #[Locked]
    public array $versions = [];

    #[Locked]
    public string $operation = '';

    public function remember(Collection $records, string $operation): void
    {
        $this->versions = $records->mapWithKeys(fn (CourseCode $code): array => [$code->id => CourseCodeEditorVersion::for($code)])->all();
        $this->operation = $operation;
    }

    public function remove(CourseCode $record): void
    {
        abort_unless(CourseCodeResource::canDelete($record), 403);
        abort_unless($this->operation === 'remove' && isset($this->versions[$record->id]), 409);
        try {
            $retained = app(AdminCourseCodeAuthoringService::class)->delete((int) $record->id, $this->versions[$record->id]);
        } catch (ValidationException $exception) {
            $this->refuse($exception);
        }
        Notification::make()->title($retained ? 'تم إيقاف الكود مع الاحتفاظ بسجله' : 'تم حذف الكود')->success()->send();
    }

    public function applyBulk(string $operation): void
    {
        abort_unless(CourseCodeResource::allows('bulk-action'), 403);
        abort_unless(in_array($operation, ['activate', 'deactivate', 'delete'], true) && $this->operation === $operation && $this->versions !== [], 409);
        try {
            $result = app(AdminCourseCodeAuthoringService::class)->bulk($operation, array_keys($this->versions), $this->versions);
        } catch (ValidationException $exception) {
            $this->refuse($exception);
        }
        Notification::make()->title($operation === 'delete' ? 'حُذف '.$result['deleted'].' كود وأُوقف '.$result['deactivated'].' كود مستخدم'
            : 'تم تحديث '.$result['changed'].' كود')->success()->send();
    }

    private function refuse(ValidationException $exception): never
    {
        Notification::make()->title(collect($exception->errors())->flatten()->first() ?: 'تغيّرت الأكواد — أعد تحميل القائمة')->warning()->send();
        throw new Halt;
    }

    public function exportUrl(string $format): string
    {
        abort_unless(in_array($format, ['csv', 'pdf'], true), 404);

        return route('admin.course-codes.'.($format === 'pdf' ? 'export-pdf' : 'export'), array_filter($this->getTableFilterState('criteria') ?? [], fn ($value): bool => filled($value)));
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('create')->label('إنشاء أكواد')->visible(fn (): bool => CourseCodeResource::canCreate())->url(route('admin.course-codes.create')),
            Action::make('csv')->label('تصدير CSV')->url(fn (): string => $this->exportUrl('csv'))->visible(fn (): bool => CourseCodeResource::allows('export', 'GET')),
            Action::make('pdf')->label('تصدير PDF')->url(fn (): string => $this->exportUrl('pdf'))->visible(fn (): bool => CourseCodeResource::allows('export-pdf', 'GET'))];
    }

    public function applyTableFilters(): void
    {
        $this->getTableFiltersForm()->getState();
        parent::applyTableFilters();
    }
}
