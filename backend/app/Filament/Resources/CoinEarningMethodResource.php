<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CoinEarningMethodResource\Pages\ListCoinEarningMethods;
use App\Models\CoinEarningMethod;
use App\Services\AdminEconomyReadService;
use App\Support\BusinessClock;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/** @extends DomainResource<CoinEarningMethod> */
final class CoinEarningMethodResource extends DomainResource
{
    protected static bool $isDiscovered = true;

    protected static ?string $model = CoinEarningMethod::class;

    protected static ?string $modelLabel = 'مهمة';

    protected static ?string $pluralModelLabel = 'مهام العملات';

    protected static ?string $recordTitleAttribute = 'title_ar';

    protected static string $permissionRoute = 'admin.coin-earning-methods';

    public static function draftFields(): array
    {
        return ['title_ar', 'title_en', 'coins_amount', 'action_key', 'campaign_key', 'action_url', 'requires_external_visit',
            'verification_delay_seconds', 'starts_at', 'ends_at', 'total_claim_limit', 'sort_order', 'is_active'];
    }

    public static function validationFieldMap(): array
    {
        return ['coin_earning_method' => 'coins_amount'];
    }

    public static function getEloquentQuery(): Builder
    {
        return app(AdminEconomyReadService::class)->methodsQuery();
    }

    public static function form(Schema $schema): Schema
    {
        $trimmed = fn (string $field): TextInput => TextInput::make($field)->maxLength(255)
            ->mutateStateForValidationUsing(fn ($state): string => trim((string) $state));

        return $schema->components([
            self::draftRecovery(),
            Section::make('المهمة')->columns(2)->schema([
                $trimmed('title_ar')->label('العنوان بالعربية')->required(),
                $trimmed('title_en')->label('العنوان بالإنجليزية')->required()->extraInputAttributes(['dir' => 'ltr']),
                TextInput::make('coins_amount')->label('عدد العملات')->required()->integer()->minValue(1),
                $trimmed('action_key')->label('مفتاح الإجراء')->required()->rules([Rule::notIn(CoinEarningMethod::AUTOMATIC_ACTION_KEYS)])
                    ->extraInputAttributes(['dir' => 'ltr']),
                TextInput::make('sort_order')->label('ترتيب الظهور')->integer()->minValue(0)->maxValue(10000)->default(100),
                Toggle::make('is_active')->label('مفعّلة')->default(false),
            ])->columnSpanFull(),
            Section::make('الوصول للمهمة')->columns(2)->schema([
                TextInput::make('action_url')->label('رابط المهمة')->url()->maxLength(2000)->extraInputAttributes(['dir' => 'ltr'])
                    ->helperText('رابط الحساب من إعدادات التطبيق يُستخدم عند ترك رابط مهمة السوشيال فارغًا'),
                Toggle::make('requires_external_visit')->label('زيارة الرابط ثم العودة')->default(true),
                TextInput::make('verification_delay_seconds')->label('مهلة العودة بالثواني')->integer()->minValue(0)->maxValue(300)->default(3),
                TextEntry::make('destination')->label('الرابط المستخدم حاليًا')->hiddenOn('create')->placeholder('لا يوجد رابط صالح')
                    ->state(fn (?CoinEarningMethod $record): ?string => $record?->resolvedActionUrl())
                    ->url(fn (?CoinEarningMethod $record): ?string => $record?->resolvedActionUrl())->openUrlInNewTab()->extraAttributes(['dir' => 'ltr']),
            ])->columnSpanFull(),
            Section::make('الحملة')->description('بتوقيت القاهرة وكل حساب يستلم الحملة مرة واحدة')->columns(2)->schema([
                TextInput::make('campaign_key')->label('مفتاح الحملة')->maxLength(80)->regex('/^[A-Za-z0-9._:-]+$/')->unique(ignoreRecord: true)
                    ->extraInputAttributes(['dir' => 'ltr']),
                TextInput::make('total_claim_limit')->label('الحد الأقصى لإجمالي المطالبات')->integer()->minValue(1)->maxValue(10000000)->placeholder('بلا حد'),
                TextInput::make('starts_at')->label('بداية الحملة')->type('datetime-local')->rules(['nullable', 'date']),
                TextInput::make('ends_at')->label('نهاية الحملة')->type('datetime-local')->rules(['nullable', 'date'])
                    ->after(fn (Get $get): string => filled($get('starts_at')) ? 'starts_at' : ''),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title_ar')->label('المهمة')->searchable(['title_ar', 'title_en', 'action_key', 'campaign_key'])->wrap()
                ->state(fn (CoinEarningMethod $record): string => $record->learnerTitleAr()),
            TextColumn::make('coins_amount')->label('العملات')->numeric()->sortable(),
            TextColumn::make('user_earnings_count')->label('المطالبات')->numeric()->sortable()->visibleFrom('md')
                ->description(fn (CoinEarningMethod $record): string => $record->total_claim_limit === null ? 'بلا حد كلي' : 'من '.$record->total_claim_limit),
            TextColumn::make('ends_at')->label('تنتهي بتوقيت القاهرة')->formatStateUsing(fn ($state): string => BusinessClock::format($state))->visibleFrom('md'),
            IconColumn::make('is_active')->label('مفعّلة')->boolean(),
        ])->defaultSort('sort_order')->defaultPaginationPageOption(25)->paginated([10, 25, 50, 100])->poll('30s')
            ->filters([TernaryFilter::make('is_active')->label('مفعّلة')])->emptyStateHeading('لا توجد مهام')->emptyStateDescription('')
            ->recordActions([ActionGroup::make([
                EditAction::make(),
                Action::make('remove')->label('حذف')->color('danger')->requiresConfirmation()
                    ->modalDescription('تختفي المهمة دون حذف سجل العملات المستلمة')
                    ->authorize(fn (CoinEarningMethod $record): bool => self::canDelete($record))
                    ->mountUsing(fn (CoinEarningMethod $record, ListCoinEarningMethods $livewire) => $livewire->rememberVersion($record))
                    ->action(fn (CoinEarningMethod $record, ListCoinEarningMethods $livewire) => $livewire->removeMethod($record)),
            ])->label('إجراءات المهمة')]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCoinEarningMethods::route('/'), 'create' => CoinEarningMethodResource\Pages\CreateCoinEarningMethod::route('/create'),
            'edit' => CoinEarningMethodResource\Pages\EditCoinEarningMethod::route('/{record}/edit')];
    }
}
