<?php

namespace App\Filament\Resources\StudentResource\RelationManagers;

use App\Filament\Resources\StudentResource;
use App\Filament\Support\StudentNoteAction;
use App\Models\UserNote;
use App\Services\AdminStudentNoteService;
use Filament\Actions\Action;
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
            StudentNoteAction::make(fn (): int => (int) $this->getOwnerRecord()->getKey(), fn (): string => $this->noteIntent,
                function (): void {
                    $this->noteIntent = (string) Str::uuid();
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
