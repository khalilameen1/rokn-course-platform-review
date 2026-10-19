<?php

namespace App\Filament\Resources\StudentResource\RelationManagers;

use App\Filament\Resources\StudentResource;
use App\Models\UserNote;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminStudentNoteService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

final class NotesRelationManager extends RelationManager
{
    protected static string $relationship = 'notes';

    protected static ?string $title = 'الملاحظات';

    #[Locked]
    public string $noteIntent;

    public function mount(): void
    {
        parent::mount();
        $this->noteIntent = (string) Str::uuid();
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return StudentResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query) => $query->with('createdBy'))->columns([
            TextColumn::make('note')->label('الملاحظة')->wrap(),
            TextColumn::make('createdBy.name')->label('كتبها')->placeholder('حساب محذوف')->visibleFrom('md'),
            TextColumn::make('created_at')->label('بتوقيت القاهرة')->dateTime('Y-m-d H:i')->timezone('Africa/Cairo')->visibleFrom('md'),
        ])->headerActions([
            Action::make('addNote')->label('إضافة ملاحظة')->authorize(fn (): bool => StudentResource::allows('notes.store'))
                ->modalSubmitActionLabel('حفظ الملاحظة')
                ->schema([Textarea::make('note')->label('الملاحظة')->required()->maxLength(1000)->rows(4)])
                ->action(function (array $data): void {
                    abort_unless(StudentResource::allows('notes.store'), 403);
                    $studentId = (int) $this->getOwnerRecord()->getKey();
                    app(AdminAuthoringCreateIntentService::class)->createResource('filament.users.notes.store', $this->noteIntent, UserNote::class,
                        ['student_id' => $studentId, 'note' => $data['note']],
                        fn ($complete): UserNote => app(AdminStudentNoteService::class)->create($studentId, $data['note'], (int) auth('web')->id(), $complete));
                    $this->noteIntent = (string) Str::uuid();
                    Notification::make()->title('تمت إضافة الملاحظة')->success()->send();
                }),
        ])->recordActions([
            Action::make('removeNote')->label('حذف')->color('danger')->requiresConfirmation()
                ->authorize(fn (): bool => StudentResource::allows('notes.delete', 'DELETE'))
                ->action(function (UserNote $record): void {
                    abort_unless(StudentResource::allows('notes.delete', 'DELETE'), 403);
                    abort_unless(app(AdminStudentNoteService::class)->delete((int) $record->id, (int) auth('web')->id(), true), 403);
                    Notification::make()->title('تم حذف الملاحظة')->success()->send();
                }),
        ])->defaultSort('id', 'desc')->defaultPaginationPageOption(5)->paginated([5, 10, 25])->poll('30s')
            ->emptyStateHeading('لا توجد ملاحظات')->emptyStateDescription('');
    }
}
