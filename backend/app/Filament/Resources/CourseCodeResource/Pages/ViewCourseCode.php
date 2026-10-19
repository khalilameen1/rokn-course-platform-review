<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseCodeResource\Pages;

use App\Filament\Resources\CourseCodeResource;
use App\Models\CourseCode;
use App\Services\AdminCourseCodeAuthoringService;
use App\Services\AdminCourseCodeReadService;
use App\Support\CourseCodeEditorVersion;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @extends ViewRecord<CourseCode> */
final class ViewCourseCode extends ViewRecord
{
    protected static string $resource = CourseCodeResource::class;

    #[Locked]
    public string $editorVersion;

    private ?string $target = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->editorVersion = CourseCodeEditorVersion::for($this->getRecord());
    }

    public function getTitle(): string
    {
        return 'تفاصيل كود المنحة';
    }

    public function targetName(): string
    {
        return $this->target ??= app(AdminCourseCodeReadService::class)->targetName($this->getRecord());
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()->visible(fn (): bool => $this->getRecord()->type === 'course'),
            Action::make('remove')->label('حذف')->color('danger')->requiresConfirmation()
                ->authorize(fn (): bool => CourseCodeResource::canDelete($this->getRecord()))
                ->modalDescription('الكود المستخدم يُوقَف مع الاحتفاظ بسجله')
                ->action(function (): void {
                    abort_unless(CourseCodeResource::canDelete($this->getRecord()), 403);
                    try {
                        $retained = app(AdminCourseCodeAuthoringService::class)->delete((int) $this->getRecord()->id, $this->editorVersion);
                    } catch (ValidationException $exception) {
                        Notification::make()->title(collect($exception->errors())->flatten()->first())->warning()->send();
                        throw new Halt;
                    }
                    Notification::make()->title($retained ? 'تم إيقاف الكود مع الاحتفاظ بسجله' : 'تم حذف الكود')->success()->send();
                    $this->redirect(CourseCodeResource::getUrl());
                })];
    }
}
