<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\AdminAuditLog;
use App\Services\AdminAuditLogReadService;
use App\Support\BusinessClock;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/** @extends ReadResource<AdminAuditLog> */
final class AuditLogResource extends ReadResource
{
    protected static bool $isDiscovered = true;

    protected static ?string $model = AdminAuditLog::class;

    protected static ?string $slug = 'audit-log';

    protected static ?string $modelLabel = 'عملية';

    protected static ?string $pluralModelLabel = 'سجل التغييرات';

    protected static string $legacyListRoute = 'admin.audit-log.index';

    public static function getEloquentQuery(): Builder
    {
        return app(AdminAuditLogReadService::class)->query();
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('actor_name')->label('المنفّذ')->wrap()->visibleFrom('md')
                ->state(fn (AdminAuditLog $record): string => self::actorLabel($record)),
            TextColumn::make('route_name')->label('الإجراء')->wrap()->placeholder('عملية غير مسماة')->extraAttributes(['dir' => 'ltr'])
                ->description(fn (AdminAuditLog $record): HtmlString => new HtmlString('<bdi dir="auto">'.e(self::actorLabel($record)).'</bdi><br><bdi dir="ltr">'.e(BusinessClock::format($record->getAttribute('occurred_at'))).'</bdi>')),
            TextColumn::make('response_status')->label('النتيجة')->badge()->color(fn ($state): string => $state < 400 ? 'success' : 'danger'),
        ])->searchable(false)->filters([Filter::make('criteria')->schema([
            Select::make('actor_id')->label('المنفّذ')->searchable()->options(fn (): array => app(AdminAuditLogReadService::class)->actors()->mapWithKeys(fn ($actor): array => [$actor->id => $actor->name ?: $actor->name_ar])->all()),
            TextInput::make('action')->label('الإجراء أو المسار')->maxLength(120)->extraInputAttributes(['dir' => 'ltr']),
            DatePicker::make('from')->label('من')->native(),
            DatePicker::make('to')->label('إلى')->native()->afterOrEqual(fn (Get $get): string => filled($get('from')) ? 'from' : ''),
        ])->indicateUsing(fn (array $data): array => collect(array_filter($data, fn ($value): bool => filled($value)))
            ->map(fn ($value, string $key): string => (['actor_id' => 'المنفّذ', 'action' => 'الإجراء', 'from' => 'من', 'to' => 'إلى'][$key] ?? $key).': '.$value)->values()->all())
            ->query(fn (Builder $query, array $data): Builder => $query->whereIn('admin_audit_logs.id', app(AdminAuditLogReadService::class)->query($data)->select('admin_audit_logs.id')->reorder()))], layout: FiltersLayout::Modal)->filtersFormColumns(2)
            ->recordActions([ViewAction::make()])->defaultPaginationPageOption(50)->paginated([10, 25, 50, 100])->poll('30s')
            ->emptyStateHeading('لا توجد عمليات مطابقة')->emptyStateDescription('');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('تفاصيل العملية')->columns(2)->schema([
                TextEntry::make('occurred_at')->label('الوقت بتوقيت القاهرة')->dateTime('Y-m-d H:i:s')->timezone(BusinessClock::timezoneName()),
                TextEntry::make('actor.name')->label('المنفّذ')->placeholder('سطر الأوامر أو حساب محذوف'),
                TextEntry::make('actor_role')->label('الدور وقت التنفيذ'),
                TextEntry::make('response_status')->label('نتيجة الطلب'),
                TextEntry::make('http_method')->label('نوع الطلب')->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('route_name')->label('الإجراء')->extraAttributes(['dir' => 'ltr'])->columnSpanFull(),
                TextEntry::make('path')->label('المسار')->extraAttributes(['dir' => 'ltr'])->columnSpanFull(),
                TextEntry::make('route_parameters')->label('معرّفات المسار')->state(fn (AdminAuditLog $record): string => self::formatValue($record->getAttribute('route_parameters')))->columnSpanFull(),
                TextEntry::make('request_fields')->label('أسماء الحقول المستلمة')->listWithLineBreaks()->placeholder('—')->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make('القيم قبل وبعد')->schema([
                RepeatableEntry::make('diff')->label('التغييرات')->state(fn (AdminAuditLog $record): array => self::diff($record))->schema([
                    TextEntry::make('subject')->label('السجل'),
                    RepeatableEntry::make('fields')->label('الحقول')->columns(3)->schema([
                        TextEntry::make('field')->label('الحقل')->extraAttributes(['dir' => 'ltr']),
                        TextEntry::make('before')->label('قبل')->extraAttributes(['dir' => 'auto']),
                        TextEntry::make('after')->label('بعد')->extraAttributes(['dir' => 'auto']),
                    ]),
                ])->placeholder('لم تُسجّل قيم قبل وبعد لهذه العملية'),
            ])->columnSpanFull(),
        ]);
    }

    public static function diff(AdminAuditLog $record): array
    {
        return array_map(function (array $change): array {
            $old = (array) ($change['old'] ?? []);
            $attributes = (array) ($change['attributes'] ?? []);
            $fields = array_unique([...array_keys($old), ...array_keys($attributes)]);

            return ['subject' => ($change['subject'] ?? '').' #'.($change['id'] ?? '').' — '.([
                'created' => 'إنشاء', 'updated' => 'تعديل', 'deleted' => 'حذف',
            ][$change['event'] ?? ''] ?? ($change['event'] ?? '')), 'fields' => array_map(fn (string $field): array => [
                'field' => $field, 'before' => self::formatValue($old[$field] ?? null), 'after' => self::formatValue($attributes[$field] ?? null),
            ], $fields)];
        }, (array) $record->getAttribute('changes'));
    }

    private static function actorLabel(AdminAuditLog $record): string
    {
        return $record->getAttribute('actor_id') ? ($record->actor->name ?? 'حساب محذوف') : 'سطر الأوامر';
    }

    private static function formatValue(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'نعم' : 'لا',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        };
    }

    public static function getPages(): array
    {
        return ['index' => AuditLogResource\Pages\ListAuditLogs::route('/'), 'view' => AuditLogResource\Pages\ViewAuditLog::route('/{record}')];
    }
}
