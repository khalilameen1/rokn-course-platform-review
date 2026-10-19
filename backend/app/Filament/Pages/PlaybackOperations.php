<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Support\PlaybackOperationsSchema;
use App\Filament\Support\ProductOperationsSchema;
use App\Services\PlaybackOperationsService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/** @property-read Schema $infolist */
final class PlaybackOperations extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'مراقبة تشغيل الفيديو';

    protected static string|\UnitEnum|null $navigationGroup = 'التشغيل';

    protected static ?int $navigationSort = 25;

    protected string $view = 'filament.pages.playback-operations';

    private ?array $cachedSnapshot = null;

    public static function canAccess(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.playback-operations.index', 'GET');
    }

    public function snapshot(): array
    {
        return $this->cachedSnapshot ??= app(PlaybackOperationsService::class)->snapshot(50);
    }

    public function getSubheading(): string
    {
        return 'تشخيص بلا بيانات طلاب أو روابط تشغيل · آخر '.$this->snapshot()['period_days'].' أيام';
    }

    public function infolist(Schema $schema): Schema
    {
        $snapshot = $this->snapshot();

        return $schema->columns(1)->components([
            ...PlaybackOperationsSchema::summary($snapshot, 10),
            Section::make('تفاصيل جودة التجربة')->collapsible()->collapsed()->schema([
                TextEntry::make('rollup_time')->label('آخر تجميع بتوقيت القاهرة')->state(ProductOperationsSchema::date(data_get($snapshot, 'rollup.last_rollup_at'))),
                TextEntry::make('recoveries')->label('أحداث التعافي')->state(data_get($snapshot, 'rollup.overall.recoveries', 'غير متاح')),
                TextEntry::make('bitrate')->label('متوسط البت الفعلي kbps')->state(data_get($snapshot, 'rollup.overall.average_effective_bitrate_kbps') ?? 'غير متاح'),
                ...collect(['by_network' => 'نوع الاتصال', 'by_os' => 'نظام التشغيل', 'errors' => 'أكثر رموز الخطأ'])
                    ->map(fn (string $label, string $key) => ProductOperationsSchema::rows('playback_'.$key, data_get($snapshot, 'rollup.'.$key, []), ['value' => 'النوع', 'sessions' => 'الجلسات'])->hiddenLabel(false)->label($label))->values()->all(),
            ])->visible($snapshot['available']),
            Section::make('أحدث أخطاء التشغيل')->collapsible()->collapsed()->schema([
                ProductOperationsSchema::rows('latest_errors', collect($snapshot['latest_errors'] ?? [])->map(fn (array $row): array => [
                    'course' => $row['course_title'], 'lesson' => $row['lesson_title'], 'error' => PlaybackOperationsSchema::errorLabel($row['last_error_code']).' · '.$row['last_error_code'],
                    'quality' => $row['effective_quality'], 'recoveries' => $row['recovery_count'], 'time' => ProductOperationsSchema::date($row['last_heartbeat_at'] ?: $row['started_at']),
                ]), ['course' => 'الكورس', 'lesson' => 'المقطع', 'error' => 'الخطأ', 'quality' => 'الجودة', 'recoveries' => 'التعافي', 'time' => 'آخر نشاط بتوقيت القاهرة']),
            ])->visible($snapshot['available']),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->heading('أحدث الجلسات')->description('يمكن إنهاء جلسة بعد '.$this->snapshot()['stale_after_minutes'].' دقائق بلا نشاط')
            ->records(fn (): array => collect($this->snapshot()['recent_sessions'] ?? [])->keyBy('session_key')->all())->paginated(false)->poll('30s')->columns([
                TextColumn::make('lesson_title')->label('المقطع')->wrap()->description(fn (array $record): string => $record['course_title']),
                TextColumn::make('state')->label('الحالة')->state(fn (array $record): string => self::stateLabel($record))->badge(),
                TextColumn::make('session_reference')->label('مرجع مجهّل')->visibleFrom('md'),
                TextColumn::make('effective_quality')->label('الجودة')->visibleFrom('md'),
                TextColumn::make('recovery_count')->label('التعافي')->numeric()->visibleFrom('lg'),
                TextColumn::make('last_heartbeat_at')->label('آخر نشاط بتوقيت القاهرة')->state(fn (array $record): string => ProductOperationsSchema::date($record['last_heartbeat_at'] ?: $record['started_at']))->visibleFrom('lg'),
            ])->recordActions([ActionGroup::make([
                Action::make('details')->label('تفاصيل الجلسة')->modalHeading('جلسة تشغيل مجهّلة')->modalSubmitAction(false)->modalCancelActionLabel('إغلاق')
                    ->schema(fn (array $record): array => self::sessionDetails($record)),
                Action::make('terminate')->label('إنهاء الجلسة')->color('danger')->requiresConfirmation()->modalHeading('إنهاء هذه الجلسة العالقة فقط')
                    ->visible(fn (array $record): bool => $record['is_stale'] && empty($record['ended_at']))
                    ->authorize(fn (): bool => $this->canTerminate())
                    ->action(function (array $record): void {
                        abort_unless(self::canAccess() && $this->canTerminate(), 403);
                        $result = app(PlaybackOperationsService::class)->terminateStale($record['session_key']);
                        $message = match ($result) {
                            'ended' => 'تم إنهاء الجلسة العالقة وتسجيل العملية', 'already_ended' => 'الجلسة منتهية بالفعل',
                            'still_active' => 'الجلسة أرسلت نشاطًا حديثًا ولم يتم إنهاؤها', default => 'لم تعد الجلسة موجودة',
                        };
                        $notification = Notification::make()->title($message);
                        ($result === 'ended' ? $notification->success() : $notification->warning())->send();
                        $this->cachedSnapshot = null;
                        $this->flushCachedTableRecords();
                        $this->cacheSchema('infolist', null);
                    }),
            ])->label('إجراءات الجلسة')])->emptyStateHeading('لا توجد جلسات تشغيل مسجلة')->emptyStateDescription('');
    }

    private function canTerminate(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.playback-operations.terminate-stale', 'POST');
    }

    private static function stateLabel(array $record): string
    {
        return filled($record['ended_at']) ? ($record['end_reason'] === 'completed' ? 'اكتملت' : 'انتهت') : ($record['is_stale'] ? 'عالقة' : 'نشطة');
    }

    private static function sessionDetails(array $record): array
    {
        $display = array_replace($record, [
            'state' => self::stateLabel($record), 'started_at' => ProductOperationsSchema::date($record['started_at']),
            'last_heartbeat_at' => ProductOperationsSchema::date($record['last_heartbeat_at']), 'ended_at' => ProductOperationsSchema::date($record['ended_at']),
            'last_error_code' => PlaybackOperationsSchema::errorLabel($record['last_error_code']),
        ]);

        return collect(['session_reference' => 'المرجع', 'course_title' => 'الكورس', 'lesson_title' => 'المقطع', 'state' => 'الحالة',
            'last_position_seconds' => 'موضع التقدم بالثواني', 'duration_seconds' => 'المدة بالثواني', 'effective_quality' => 'الجودة',
            'recovery_count' => 'التعافي', 'last_error_code' => 'رمز الخطأ', 'started_at' => 'بدأت بتوقيت القاهرة',
            'last_heartbeat_at' => 'آخر نشاط بتوقيت القاهرة', 'ended_at' => 'انتهت بتوقيت القاهرة'])
            ->map(fn (string $label, string $key): TextEntry => TextEntry::make($key)->label($label)->state($display[$key] ?? 'غير متاح'))->values()->all();
    }
}
