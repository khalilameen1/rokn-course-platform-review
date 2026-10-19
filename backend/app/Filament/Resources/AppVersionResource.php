<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AppVersionResource\Pages\ListAppVersions;
use App\Models\AppVersion;
use App\Services\AdminAppReleaseReadService;
use App\Services\AppReleasePolicyService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** @extends DomainResource<AppVersion> */
final class AppVersionResource extends DomainResource
{
    protected static bool $isDiscovered = true;

    protected static string|\UnitEnum|null $navigationGroup = 'التشغيل';

    protected static ?int $navigationSort = 50;

    protected static ?string $model = AppVersion::class;

    protected static ?string $modelLabel = 'إصدار';

    protected static ?string $pluralModelLabel = 'إصدارات التطبيق';

    protected static ?string $recordTitleAttribute = 'version_name';

    protected static string $permissionRoute = 'admin.app-versions';

    public static function channels(): array
    {
        return ['play' => 'Google Play', 'appstore' => 'App Store', 'direct' => 'Android مباشر'];
    }

    public static function draftFields(): array
    {
        return ['distribution_channel', 'version_name', 'version_code', 'build_number', 'is_active', 'is_force_update',
            'download_url', 'update_message_ar', 'update_message_en', 'release_notes_ar', 'release_notes_en'];
    }

    public static function getEloquentQuery(): Builder
    {
        return app(AdminAppReleaseReadService::class)->listQuery();
    }

    public static function canEdit(Model $record): bool
    {
        return $record->getAttribute('distribution_channel') !== null && parent::canEdit($record);
    }

    public static function form(Schema $schema): Schema
    {
        $identity = fn (string $field) => TextInput::make($field)->label($field === 'version_code' ? 'كود إصدار Android' : 'رقم بناء iOS')
            ->integer()->minValue(1)->required()->disabledOn('edit')->dehydrated()
            ->visible(fn (Get $get): bool => ($get('distribution_channel') === 'appstore') === ($field === 'build_number'))
            ->rules(fn (Get $get, ?AppVersion $record): array => [Rule::unique('app_versions', $field)
                ->where(fn ($query) => $query->where('distribution_channel', $get('distribution_channel')))->ignore($record?->id)]);

        return $schema->components([
            self::draftRecovery(),
            Section::make('هوية الإصدار')->columns(2)->schema([
                Select::make('distribution_channel')->label('قناة التوزيع')->options(self::channels())->required()->live()
                    ->disabledOn('edit')->dehydrated(),
                TextInput::make('version_name')->label('اسم الإصدار')->required()->maxLength(40)
                    ->regex('/^\d+(?:\.\d+){1,3}$/')->disabledOn('edit')->dehydrated()->extraInputAttributes(['dir' => 'ltr']),
                $identity('version_code'), $identity('build_number'),
            ])->columnSpanFull(),
            Section::make('التحديث')->columns(2)->schema([
                TextInput::make('download_url')->label('رابط التحديث')->url()->maxLength(2048)->columnSpanFull()
                    ->required(fn (Get $get): bool => (bool) $get('is_active') || (bool) $get('is_force_update'))
                    ->extraInputAttributes(['dir' => 'ltr'])->rules(fn (Get $get): array => [
                        function (string $attribute, $value, $fail) use ($get): void {
                            if (! app(AppReleasePolicyService::class)->isAllowedDownloadUrl((string) $get('distribution_channel'), $value)) {
                                $fail('استخدم رابط التحديث الرسمي المطابق لقناة التوزيع');
                            }
                        },
                    ]),
                Toggle::make('is_active')->label('نشط')->default(false)->live(),
                Toggle::make('is_force_update')->label('تحديث إجباري')->default(false)->live(),
                Textarea::make('update_message_ar')->label('رسالة التحديث بالعربية')->maxLength(500)->rows(2),
                Textarea::make('update_message_en')->label('رسالة التحديث بالإنجليزية')->maxLength(500)->rows(2)->extraInputAttributes(['dir' => 'ltr']),
            ])->columnSpanFull(),
            Section::make('ملاحظات الإصدار')->columns(2)->collapsible()->schema([
                Textarea::make('release_notes_ar')->label('بالعربية')->maxLength(5000)->rows(4),
                Textarea::make('release_notes_en')->label('بالإنجليزية')->maxLength(5000)->rows(4)->extraInputAttributes(['dir' => 'ltr']),
            ])->columnSpanFull(),
        ]);
    }

    /** Normalize only the mutually exclusive platform identifiers; policy stays with the owning service. */
    public static function authoringData(array $data): array
    {
        $channel = $data['distribution_channel'];
        $data['platform'] = app(AppReleasePolicyService::class)->platformForChannel($channel);
        $data['version_code'] = $channel === 'appstore' ? null : (int) $data['version_code'];
        $data['build_number'] = $channel === 'appstore' ? (int) $data['build_number'] : null;
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['is_force_update'] = (bool) ($data['is_force_update'] ?? false);

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('version_name')->label('الإصدار')->searchable()->sortable()->extraAttributes(['dir' => 'ltr']),
            TextColumn::make('distribution_channel')->label('القناة')->badge()->formatStateUsing(fn (?string $state): string => self::channels()[$state] ?? 'قديم غير محدد')->placeholder('قديم غير محدد'),
            TextColumn::make('identifier')->label('رقم البناء')->state(fn (AppVersion $record) => $record->platform === 'ios' ? $record->build_number : $record->version_code),
            IconColumn::make('is_active')->label('نشط')->boolean(),
            IconColumn::make('is_force_update')->label('إجباري')->boolean()->toggleable(),
            TextColumn::make('created_at')->label('تاريخ الإضافة')->date()->toggleable(isToggledHiddenByDefault: true),
        ])->defaultSort('id', 'desc')->poll('30s')->emptyStateHeading('لا توجد إصدارات')->emptyStateDescription('')
            ->filters([SelectFilter::make('distribution_channel')->label('القناة')->options(self::channels()),
                SelectFilter::make('is_active')->label('الحالة')->options(['1' => 'نشط', '0' => 'غير نشط'])])
            ->recordActions([
                EditAction::make(),
                Action::make('toggleActive')->label(fn (AppVersion $record): string => $record->is_active ? 'إيقاف' : 'تفعيل')
                    ->authorize(fn (AppVersion $record): bool => self::getAuthorizationResponse('update', $record)->allowed())
                    ->requiresConfirmation()->mountUsing(fn (AppVersion $record, ListAppVersions $livewire) => $livewire->rememberVersion($record))
                    ->action(fn (AppVersion $record, ListAppVersions $livewire) => $livewire->changeRelease($record, 'toggleActive')),
                Action::make('remove')->label('حذف')->color('danger')->requiresConfirmation()
                    ->authorize(fn (AppVersion $record): bool => self::canDelete($record))
                    ->mountUsing(fn (AppVersion $record, ListAppVersions $livewire) => $livewire->rememberVersion($record))
                    ->action(fn (AppVersion $record, ListAppVersions $livewire) => $livewire->changeRelease($record, 'delete')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAppVersions::route('/'),
            'create' => AppVersionResource\Pages\CreateAppVersion::route('/create'),
            'edit' => AppVersionResource\Pages\EditAppVersion::route('/{record}/edit')];
    }
}
