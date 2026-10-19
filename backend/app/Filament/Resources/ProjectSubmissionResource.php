<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Resources\ProjectSubmissionResource\Pages\ListProjectSubmissions;
use App\Filament\Resources\ProjectSubmissionResource\Pages\ViewProjectSubmission;
use App\Filament\Resources\ProjectSubmissionResource\ProjectSubmissionDetailsSchema;
use App\Models\ProjectSubmission;
use App\Services\AdminProjectSubmissionReviewReadService;
use App\Support\BusinessClock;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/** @extends ReadResource<ProjectSubmission> */
final class ProjectSubmissionResource extends ReadResource
{
    protected static bool $isDiscovered = true;

    protected static ?string $model = ProjectSubmission::class;

    protected static ?string $modelLabel = 'محاولة مشروع';

    protected static ?string $pluralModelLabel = 'مشاريع الطلاب';

    protected static string|\UnitEnum|null $navigationGroup = 'المراجعة';

    protected static ?int $navigationSort = 10;

    protected static string $legacyListRoute = 'admin.project-submissions.index';

    public static function administrator(): bool
    {
        return app(AdminPermissionMatrix::class)->isAdministrator(auth('web')->user());
    }

    public static function getEloquentQuery(): Builder
    {
        return app(AdminProjectSubmissionReviewReadService::class)->query([], self::administrator());
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('course')->label('الكورس والمشروع')->wrap()
                ->state(fn (ProjectSubmission $record): string => $record->project?->section?->course?->title ?: 'كورس غير متاح')
                ->description(fn (ProjectSubmission $record): string => ($record->project?->section?->title ?: 'مشروع #'.$record->project_id)
                    .' — '.(self::administrator() ? ($record->user?->getFilamentName() ?: 'حساب محذوف') : 'محاولة '.Str::limit($record->public_id, 12))),
            TextColumn::make('review_status')->label('المراجعة')->badge()
                ->formatStateUsing(fn (string $state): string => AdminProjectSubmissionReviewReadService::STATUSES[$state] ?? $state)
                ->color(fn (string $state): string => match ($state) {
                    'passed' => 'success', 'needs_resubmission' => 'danger', default => 'warning'
                }),
            TextColumn::make('public_id')->label('المحاولة')->limit(18)->wrap()->visibleFrom('md')
                ->description(fn (ProjectSubmission $record): string => ['valid' => 'تسليم صالح للمراجعة', 'invalid' => 'تسليم غير كافٍ', 'unknown' => 'لم يُفحص بعد'][$record->effort_status] ?? 'غير معروف'),
            TextColumn::make('student')->label('الطالب')->visible(fn (): bool => self::administrator())->visibleFrom('md')
                ->state(fn (ProjectSubmission $record): string => $record->user?->getFilamentName() ?: 'حساب محذوف')
                ->description(fn (ProjectSubmission $record): ?string => $record->user?->email)->wrap(),
            TextColumn::make('submitted_at')->label('الإرسال بتوقيت القاهرة')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName())->visibleFrom('lg'),
        ])->searchable()->searchPlaceholder(fn (): string => self::administrator() ? 'الطالب أو الكورس أو المحاولة' : 'الكورس أو المشروع أو المحاولة')
            ->searchUsing(fn (Builder $query, string $search): Builder => app(AdminProjectSubmissionReviewReadService::class)->applyFilters($query, ['search' => $search], self::administrator()))
            ->filters([SelectFilter::make('review_status')->label('حالة المراجعة')->options(AdminProjectSubmissionReviewReadService::STATUSES)])
            ->recordActions([Action::make('review')->label('مراجعة')->url(fn (ProjectSubmission $record): string => self::getUrl('view', ['record' => $record]))])
            ->recordUrl(null)->poll('30s')->defaultPaginationPageOption(25)->emptyStateHeading('لا توجد محاولات مطابقة')->emptyStateDescription('');
    }

    public static function listUrl(array $filters = []): string
    {
        return self::getUrl('index', ['search' => $filters['search'] ?? null, 'filters' => ['review_status' => ['value' => $filters['status'] ?? null]]]);
    }

    public static function getPages(): array
    {
        return ['index' => ListProjectSubmissions::route('/'), 'view' => ViewProjectSubmission::route('/{record}')];
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProjectSubmissionDetailsSchema::configure($schema);
    }

    public static function allows(string $action): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.project-submissions.'.$action, 'POST');
    }
}
