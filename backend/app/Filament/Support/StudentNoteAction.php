<?php

namespace App\Filament\Support;

use App\Filament\Resources\StudentResource;
use App\Models\UserNote;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminStudentNoteService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

/** Same native action and durable receipt for quick notes and the student file. */
final class StudentNoteAction
{
    public static function make(Closure $studentId, Closure $intent, Closure $completed): Action
    {
        return Action::make('addNote')->label('إضافة ملاحظة')->authorize(fn (): bool => StudentResource::allows('notes.store'))
            ->modalSubmitActionLabel('حفظ الملاحظة')
            ->schema([Textarea::make('note')->label('الملاحظة')->required()->maxLength(1000)->rows(4)])
            ->action(function (Action $action, array $data) use ($studentId, $intent, $completed): void {
                abort_unless(StudentResource::allows('notes.store'), 403);
                $ownerId = (int) $action->evaluate($studentId);
                try {
                    app(AdminAuthoringCreateIntentService::class)->createResource('filament.users.notes.store', $action->evaluate($intent), UserNote::class,
                        ['student_id' => $ownerId, 'note' => $data['note']],
                        fn ($complete): UserNote => app(AdminStudentNoteService::class)->create($ownerId, $data['note'], (int) auth('web')->id(), $complete));
                } catch (ValidationException $exception) {
                    Notification::make()->title((string) collect($exception->errors())->flatten()->first())->warning()->send();
                    throw new Halt;
                }
                $action->evaluate($completed);
                Notification::make()->title('تمت إضافة الملاحظة')->success()->send();
            });
    }
}
