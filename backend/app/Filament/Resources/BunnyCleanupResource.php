<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Resources\BunnyCleanupResource\Pages\ListBunnyCleanup;
use App\Models\BunnyVideoCleanupCandidate;
use App\Services\AdminBunnyCleanupReviewService;
use App\Support\BusinessClock;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** @extends ReadResource<BunnyVideoCleanupCandidate> */
final class BunnyCleanupResource extends ReadResource
{
    protected static bool $isDiscovered = true;

    protected static ?string $model = BunnyVideoCleanupCandidate::class;

    protected static ?string $pluralModelLabel = 'تنظيف الفيديوهات';

    protected static ?string $modelLabel = 'فيديو مرشح للتنظيف';

    protected static string|\UnitEnum|null $navigationGroup = 'التشغيل';

    protected static ?int $navigationSort = 30;

    protected static string $legacyListRoute = 'admin.settings';

    public static function allowsApproval(bool $batch = false): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(),
            'admin.settings.bunny-cleanup.'.($batch ? 'approve-batch' : 'approve'), 'POST');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('video_guid')->label('معرّف الفيديو')->wrap()->extraAttributes(['dir' => 'ltr'])->searchable()
                ->description(fn (BunnyVideoCleanupCandidate $record): string => self::status($record).' — '.(string) $record->getAttribute('reason')),
            TextColumn::make('state')->label('الحالة')->badge()->state(fn (BunnyVideoCleanupCandidate $record): string => self::status($record))->visibleFrom('md'),
            TextColumn::make('eligible_after')->label('موعد الأهلية بتوقيت القاهرة')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName())->sortable()->visibleFrom('md'),
            TextColumn::make('attempts')->label('المحاولات')->numeric()->visibleFrom('md'),
            TextColumn::make('last_error')->label('آخر خطأ')->wrap()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('review')->label('المراجعة')->options(['pending' => 'بانتظار المراجعة', 'approved' => 'معتمد', 'deleted' => 'تم التنظيف', 'failed' => 'بها خطأ', 'verified' => 'أسباب موثقة'])
                ->default('pending')->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? '') {
                    'pending' => $query->whereNull('reviewed_at')->whereNull('remote_deleted_at'),
                    'approved' => $query->whereNotNull('reviewed_at')->whereNull('remote_deleted_at'),
                    'deleted' => $query->whereNotNull('remote_deleted_at'),
                    'failed' => $query->whereNotNull('last_error'),
                    'verified' => $query->whereIn('reason', AdminBunnyCleanupReviewService::VERIFIED_REASONS),
                    default => $query,
                }),
        ])->recordActions([
            Action::make('approve')->label('اعتماد التنظيف')->authorize(fn (): bool => self::allowsApproval())
                ->visible(fn (BunnyVideoCleanupCandidate $record): bool => ! $record->getAttribute('reviewed_at') && ! $record->getAttribute('remote_deleted_at'))
                ->requiresConfirmation()->modalDescription('سيعيد العامل فحص ارتباط الفيديو بالمحتوى قبل حذفه بعد فترة الاحتفاظ')
                ->action(fn (BunnyVideoCleanupCandidate $record) => self::approve([$record->getKey()], false)),
        ])->toolbarActions([
            BulkAction::make('approve')->label('اعتماد المحدد')->authorize(fn (): bool => self::allowsApproval(true))
                ->requiresConfirmation()->modalDescription('لن تُحذف الملفات الآن ولن تُعتمد الفيديوهات المرتبطة بالمحتوى')
                ->action(fn (Collection $records) => self::approve($records->modelKeys(), true))->deselectRecordsAfterCompletion(),
        ])->checkIfRecordIsSelectableUsing(fn (BunnyVideoCleanupCandidate $record): bool => ! $record->getAttribute('reviewed_at') && ! $record->getAttribute('remote_deleted_at'))
            ->selectCurrentPageOnly()->defaultSort('id', 'desc')->poll('30s')->defaultPaginationPageOption(25)->paginated([10, 25, 50, 100])
            ->recordUrl(null)->emptyStateHeading('لا توجد فيديوهات في قائمة التنظيف')->emptyStateDescription('');
    }

    public static function approve(array $ids, bool $batch): void
    {
        abort_unless(self::allowsApproval($batch), 403);
        abort_if(count($ids) > 100, 422);
        $result = app(AdminBunnyCleanupReviewService::class)->approveBatch($ids, (int) auth('web')->id());
        Notification::make()->title('تم اعتماد '.$result['approved'].' فيديو')->body('تجاوز الفيديوهات المرتبطة بالمحتوى '.$result['skipped_active'])->success()->send();
    }

    private static function status(BunnyVideoCleanupCandidate $record): string
    {
        return $record->getAttribute('remote_deleted_at') ? 'تم التنظيف' : ($record->getAttribute('reviewed_at') ? 'معتمد وينتظر العامل' : 'يحتاج مراجعة');
    }

    public static function getPages(): array
    {
        return ['index' => ListBunnyCleanup::route('/')];
    }
}
