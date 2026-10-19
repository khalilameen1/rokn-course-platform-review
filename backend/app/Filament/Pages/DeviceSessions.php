<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Widgets\DeviceSessionSummary;
use App\Services\AdminDeviceSessionReadService;
use App\Services\DeviceLoginService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class DeviceSessions extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'جلسات الأجهزة';

    protected static string|\UnitEnum|null $navigationGroup = 'التشغيل';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'device-sessions';

    protected string $view = 'filament.pages.device-sessions';

    public static function canAccess(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.user-sessions.index', 'GET');
    }

    public function getSubheading(): string
    {
        return app(AdminDeviceSessionReadService::class)->ready() ? 'الجلسات النشطة فقط' : 'شغّل migrations لتفعيل سجل الجلسات';
    }

    public function table(Table $table): Table
    {
        return $table->heading('جلسات الدخول')->modelLabel('جلسة')->pluralModelLabel('الجلسات')
            ->records(fn (?string $search, ?array $filters, int $page, int $recordsPerPage) => app(AdminDeviceSessionReadService::class)->paginate(
                $search, $filters['platform']['value'] ?? null, $recordsPerPage, $page))
            ->searchable()->searchPlaceholder('اسم الطالب أو بريده')->defaultPaginationPageOption(50)->paginated([10, 25, 50, 100])->poll(null)
            ->columns([
                TextColumn::make('user_name')->label('الطالب')->wrap()->description(fn (array $record): string => $record['email']),
                TextColumn::make('device_class')->label('الجهاز')->description(fn (array $record): string => self::platformLabel($record['platform'])),
                TextColumn::make('app_version')->label('إصدار التطبيق')->visibleFrom('md')->description(fn (array $record): ?string => $record['app_build']),
                TextColumn::make('last_used_at')->label('آخر نشاط بتوقيت القاهرة')->visibleFrom('md'),
                TextColumn::make('expired_at')->label('تنتهي بتوقيت القاهرة')->visibleFrom('md'),
            ])->filters([SelectFilter::make('platform')->label('النظام')->options(['android' => 'Android', 'ios' => 'iOS', 'other' => 'أخرى'])])
            ->emptyStateHeading('لا توجد جلسات نشطة مطابقة')->emptyStateDescription('')
            ->recordActions([ActionGroup::make([
                Action::make('details')->label('تفاصيل')->modalHeading('تفاصيل الجلسة')->modalSubmitAction(false)->modalCancelActionLabel('إغلاق')
                    ->fillForm(fn (array $record): array => $record)->schema([
                        TextEntry::make('user_name')->label('الطالب'), TextEntry::make('email')->label('البريد')->extraAttributes(['dir' => 'ltr']),
                        TextEntry::make('device_class')->label('الجهاز'), TextEntry::make('platform')->label('النظام')->formatStateUsing(fn ($state): string => self::platformLabel($state)),
                        TextEntry::make('app_version')->label('الإصدار'), TextEntry::make('app_build')->label('رقم البناء')->placeholder('—'),
                        TextEntry::make('auth_provider')->label('طريقة الدخول'), TextEntry::make('issued_at')->label('بدأت بتوقيت القاهرة'),
                        TextEntry::make('last_used_at')->label('آخر نشاط بتوقيت القاهرة'), TextEntry::make('expired_at')->label('تنتهي بتوقيت القاهرة'),
                    ]),
                Action::make('revoke')->label('إنهاء')->color('danger')->requiresConfirmation()->modalHeading('إنهاء هذه الجلسة فقط')
                    ->authorize(fn (): bool => $this->canRevoke())
                    ->action(function (array $record): void {
                        abort_unless($this->canRevoke(), 403);
                        app(DeviceLoginService::class)->revokeSessionFromDashboard($record['session_id']);
                        $this->flushCachedTableRecords();
                        Notification::make()->title('تم إنهاء جلسة الجهاز')->success()->send();
                    }),
            ])->label('إجراءات الجلسة')]);
    }

    public static function platformLabel(?string $platform): string
    {
        return match ($platform) {
            'android' => 'Android', 'ios' => 'iOS', default => 'أخرى',
        };
    }

    private function canRevoke(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.user-sessions.destroy', 'DELETE');
    }

    protected function getHeaderWidgets(): array
    {
        return [DeviceSessionSummary::class];
    }

    public function getWidgetData(): array
    {
        return ['summary' => app(AdminDeviceSessionReadService::class)->summary()];
    }
}
