<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\StudentProgressResource\Pages\ListStudentProgress;
use App\Filament\Resources\StudentProgressResource\Pages\ViewStudentProgress;
use App\Filament\Resources\StudentProgressResource\StudentProgressSchema;
use App\Models\User;
use App\Services\AdminStudentProgressReadService;
use App\Support\AdminStudentSearch;
use App\Support\BusinessClock;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** @extends ReadResource<User> */
final class StudentProgressResource extends ReadResource
{
    protected static bool $isDiscovered = true;

    protected static ?string $model = User::class;

    protected static ?string $slug = 'student-progress';

    protected static ?string $modelLabel = 'تقدم طالب';

    protected static ?string $pluralModelLabel = 'تقدم الطلاب';

    protected static string|\UnitEnum|null $navigationGroup = 'الطلبة';

    protected static ?int $navigationSort = 20;

    protected static string $legacyListRoute = 'admin.student-progress.index';

    public static function getEloquentQuery(): Builder
    {
        return app(AdminStudentProgressReadService::class)->query();
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('student')->label('الطالب')->state(fn (User $record): string => $record->getFilamentName())->description(fn (User $record): string => '#'.$record->id)->wrap(),
            TextColumn::make('phone')->label('الهاتف')->extraAttributes(['dir' => 'ltr'])->visibleFrom('md'),
            TextColumn::make('course')->label('التعلم الحالي')->state(fn (User $record): string => data_get($record->getAttribute('learning_progress'), 'course.title', 'لا يوجد كورس نشط'))->wrap(),
            TextColumn::make('progress')->label('التقدم')->state(fn (User $record): ?string => data_get($record->getAttribute('learning_progress'), 'has_enrollment')
                ? data_get($record->getAttribute('learning_progress'), 'progress.progress_percentage').'%' : null)->placeholder('—')
                ->description(fn (User $record): ?string => data_get($record->getAttribute('learning_progress'), 'has_enrollment')
                    ? data_get($record->getAttribute('learning_progress'), 'progress.completed_sections').' من '.data_get($record->getAttribute('learning_progress'), 'progress.total_sections') : null),
            TextColumn::make('last_activity')->label('آخر نشاط بتوقيت القاهرة')->state(fn (User $record) => data_get($record->getAttribute('learning_progress'), 'progress.last_activity'))
                ->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName())->placeholder('—')->visibleFrom('lg'),
        ])->searchable()->searchPlaceholder('الاسم أو البريد أو رقم الطالب')
            ->searchUsing(function (Builder $query, string $search): Builder {
                AdminStudentSearch::apply($query, $search);

                return $query;
            })
            ->filters([SelectFilter::make('course_id')->label('الكورس')->searchable()
                ->options(fn (): array => app(AdminStudentProgressReadService::class)->courses()->mapWithKeys(fn ($course): array => [$course->id => $course->title])->all())
                ->query(fn (Builder $query, array $data): Builder => app(AdminStudentProgressReadService::class)->applyFilters($query, ['course_id' => $data['value'] ?? null]))])
            ->recordActions([Action::make('progress')->label('التفاصيل')->url(fn (User $record): string => self::getUrl('view', ['record' => $record]))])
            ->recordUrl(null)->poll('30s')->defaultPaginationPageOption(25)->emptyStateHeading('لا يوجد طلاب بهذه التصفيات')->emptyStateDescription('');
    }

    public static function infolist(Schema $schema): Schema
    {
        return StudentProgressSchema::configure($schema);
    }

    public static function listUrl(array $filters = []): string
    {
        return self::getUrl('index', ['search' => $filters['search'] ?? null, 'filters' => ['course_id' => ['value' => $filters['course_id'] ?? null]]]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStudentProgress::route('/'), 'view' => ViewStudentProgress::route('/{record}')];
    }
}
