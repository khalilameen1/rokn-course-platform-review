<?php

namespace App\Filament\Resources\StudentResource\RelationManagers;

use App\Filament\Resources\StudentResource;
use App\Models\ProjectSubmission;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ProjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'projectSubmissions';

    protected static ?string $title = 'المشاريع';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return StudentResource::canView($ownerRecord) && StudentResource::allowsRoute('admin.project-submissions.index', 'GET');
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query) => $query->with('project.section.course'))->columns([
            TextColumn::make('project.section.title')->label('المشروع')->wrap()
                ->description(fn (ProjectSubmission $record): ?string => $record->project?->section?->course?->title),
            TextColumn::make('review_status')->label('الحالة')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                ProjectSubmission::STATUS_PASSED => 'اجتاز', ProjectSubmission::STATUS_NEEDS_RESUBMISSION => 'يحتاج إعادة', default => 'قيد المراجعة'
            }),
            TextColumn::make('submitted_at')->label('بتوقيت القاهرة')->dateTime('Y-m-d H:i')->timezone('Africa/Cairo')->visibleFrom('md'),
        ])->recordActions([Action::make('review')->label('عرض المشروع')->url(fn (ProjectSubmission $record): string => route('admin.project-submissions.show', $record))])
            ->defaultSort('id', 'desc')->defaultPaginationPageOption(10)->paginated([10, 25, 50])->poll('30s')
            ->emptyStateHeading('لا توجد محاولات')->emptyStateDescription('');
    }
}
