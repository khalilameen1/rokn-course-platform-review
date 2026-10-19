<?php

namespace App\Filament\Resources\TeacherResource\RelationManagers;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Resources\TeacherResource;
use App\Models\Course;
use App\Services\AdminTeacherReadService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class CoursesRelationManager extends RelationManager
{
    protected static string $relationship = 'teachingCourses';

    protected static ?string $title = 'الكورسات المسندة';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return TeacherResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        $counts = app(AdminPermissionMatrix::class)->isAdministrator(auth('web')->user());

        return $table->modifyQueryUsing(fn (Builder $query): Builder => app(AdminTeacherReadService::class)->profileCourses($query, $counts))
            ->columns([
                TextColumn::make('name_ar')->label('الكورس')->wrap()->searchable(),
                TextColumn::make('active_enrollments_count')->label('عدد الطلاب')->numeric()->visible($counts)->visibleFrom('md'),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->date('Y-m-d')->visibleFrom('md'),
            ])->recordActions([Action::make('course')->label('عرض')->url(fn (Course $record): string => route('admin.courses.show', $record))])
            ->defaultSort('courses.id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(10)
            ->emptyStateHeading('لا توجد كورسات مسندة')->emptyStateDescription('');
    }
}
