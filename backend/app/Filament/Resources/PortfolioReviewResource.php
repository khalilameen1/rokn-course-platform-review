<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PortfolioReviewResource\Pages\ListPortfolioReviews;
use App\Models\User;
use App\Services\AdminPortfolioReviewReadService;
use App\Support\BusinessClock;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** @extends ReadResource<User> */
final class PortfolioReviewResource extends ReadResource
{
    protected static bool $isDiscovered = true;

    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'معرض';

    protected static ?string $pluralModelLabel = 'مراجعة البورتفوليو';

    protected static string|\UnitEnum|null $navigationGroup = 'المراجعة';

    protected static ?int $navigationSort = 20;

    protected static string $legacyListRoute = 'admin.portfolio-reviews.index';

    public static function getEloquentQuery(): Builder
    {
        return app(AdminPortfolioReviewReadService::class)->query();
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('owner_name')->label('صاحب المعرض')->state(fn (User $record): string => $record->getFilamentName())->wrap()
                ->description(fn (User $record): string => (AdminPortfolioReviewReadService::STATUSES[AdminPortfolioReviewReadService::status($record)] ?? 'بانتظار المراجعة')
                    .' · النسخة '.$record->portfolio_sharing_revision),
            TextColumn::make('selected_projects_count')->label('الأعمال')->numeric(),
            TextColumn::make('portfolio_sharing_revision')->label('النسخة')->numeric()->visibleFrom('md'),
            TextColumn::make('review_state')->label('المراجعة')->state(fn (User $record): string => AdminPortfolioReviewReadService::status($record))
                ->formatStateUsing(fn (string $state): string => AdminPortfolioReviewReadService::STATUSES[$state] ?? $state)->badge()->visibleFrom('lg')
                ->color(fn (string $state): string => ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'suspended' => 'danger'][$state] ?? 'gray'),
            TextColumn::make('updated_at')->label('آخر تحديث بتوقيت القاهرة')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName())->visibleFrom('md'),
        ])->searchable()->searchPlaceholder('اسم صاحب المعرض')
            ->searchUsing(fn (Builder $query, string $search): Builder => app(AdminPortfolioReviewReadService::class)->search($query, $search))
            ->filters([SelectFilter::make('status')->label('حالة المراجعة')->options(AdminPortfolioReviewReadService::STATUSES)->default('pending')
                ->query(fn (Builder $query, array $data): Builder => app(AdminPortfolioReviewReadService::class)->filterStatus($query, $data['value'] ?? null))])
            ->recordActions([Action::make('preview')->label('معاينة ومراجعة')->url(fn (User $record): string => route('admin.portfolio-preview.show', $record))])
            ->recordUrl(null)->poll('30s')->defaultPaginationPageOption(25)->emptyStateHeading('لا توجد معارض بهذه الحالة')->emptyStateDescription('');
    }

    public static function listUrl(array $filters = []): string
    {
        return self::getUrl('index', ['filters' => ['status' => ['value' => $filters['status'] ?? 'pending']]]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPortfolioReviews::route('/')];
    }
}
