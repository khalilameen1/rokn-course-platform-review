<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\RewardRuleResource\Pages\ListRewardRules;
use App\Models\RewardRule;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/** @extends DomainResource<RewardRule> */
final class RewardRuleResource extends DomainResource
{
    protected static bool $isDiscovered = true;

    protected static ?string $model = RewardRule::class;

    protected static ?string $modelLabel = 'قاعدة مكافأة';

    protected static ?string $pluralModelLabel = 'المكافآت التلقائية';

    protected static ?string $recordTitleAttribute = 'title_ar';

    protected static string $permissionRoute = 'admin.reward-rules';

    public static function draftFields(): array
    {
        return ['event_key', 'title_ar', 'title_en', 'coins_amount', 'interval_count', 'daily_cap', 'rolling_30_day_cap', 'sort_order', 'is_active'];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('event_key', array_keys(RewardRule::EVENTS));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            self::draftRecovery(),
            Section::make('المكافأة')->columns(2)->schema([
                Select::make('event_key')->label('الحدث')->required()->live()
                    ->options(fn (?RewardRule $record): array => array_diff_key(RewardRule::EVENTS, RewardRule::query()
                        ->when($record, fn (Builder $query): Builder => $query->whereKeyNot($record->id))->pluck('event_key', 'event_key')->all()))
                    ->rules([Rule::in(array_keys(RewardRule::EVENTS))])->unique(ignoreRecord: true),
                TextInput::make('coins_amount')->label('عدد العملات')->required()->integer()->minValue(0)->maxValue(1000000),
                TextInput::make('title_ar')->label('الاسم بالعربية')->required()->maxLength(255),
                TextInput::make('title_en')->label('الاسم بالإنجليزية')->maxLength(255)->extraInputAttributes(['dir' => 'ltr']),
                TextInput::make('sort_order')->label('الترتيب')->integer()->minValue(0)->maxValue(10000)->default(100)->required(),
                Toggle::make('is_active')->label('مفعّلة')->default(false),
            ])->columnSpanFull(),
            Section::make('حدود المنح')->description('الصفر يوقف المنح عند هذا الحد')->columns(2)->schema([
                TextInput::make('interval_count')->label(fn (Get $get): string => $get('event_key') === 'streak_milestone' ? 'مدة الاستمرارية بالأيام' : 'مدة الدراسة بالدقائق')
                    ->integer()->minValue(fn (Get $get): int => $get('event_key') === 'streak_milestone' ? 2 : 1)->maxValue(1440)
                    ->visible(fn (Get $get): bool => in_array($get('event_key'), ['streak_milestone', 'study_session'], true))->required(),
                TextInput::make('daily_cap')->label('حد عملات الدراسة يوميًا')->integer()->minValue(0)->maxValue(1000000)
                    ->visible(fn (Get $get): bool => $get('event_key') === 'study_session'),
                TextInput::make('rolling_30_day_cap')->label(fn (Get $get): string => $get('event_key') === 'first_project_passed' ? 'سقف عملات أول مشروع' : 'حد العملات خلال 30 يومًا')
                    ->integer()->minValue(0)->maxValue(1000000)->visible(fn (Get $get): bool => $get('event_key') !== 'welcome_bonus')
                    ->required(fn (Get $get): bool => (bool) $get('is_active') && (int) $get('coins_amount') > 0 && in_array($get('event_key'), ['daily_checkin', 'streak_milestone', 'study_session', 'course_completed'], true)),
            ])->visible(fn (Get $get): bool => filled($get('event_key')) && $get('event_key') !== 'welcome_bonus')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title_ar')->label('المكافأة')->searchable(['title_ar', 'title_en'])->wrap()
                ->description(fn (RewardRule $record): string => RewardRule::EVENTS[$record->event_key]),
            TextColumn::make('coins_amount')->label('العملات')->numeric()->sortable(),
            TextColumn::make('rolling_30_day_cap')->label('حد العملات خلال 30 يومًا')->numeric()->placeholder('—')->visibleFrom('md'),
            TextColumn::make('sort_order')->label('الترتيب')->sortable()->visibleFrom('md'),
            IconColumn::make('is_active')->label('مفعّلة')->boolean(),
        ])->defaultSort('sort_order')->paginated([10, 25])->poll('30s')
            ->filters([TernaryFilter::make('is_active')->label('مفعّلة')])
            ->emptyStateHeading('لا توجد مكافآت تلقائية')->emptyStateDescription('')->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('remove')->label('حذف')->color('danger')->requiresConfirmation()
                        ->modalDescription('يتوقف منح المكافأة دون تغيير العملات التي استلمها الطلاب')
                        ->authorize(fn (RewardRule $record): bool => self::canDelete($record))
                        ->mountUsing(fn (RewardRule $record, ListRewardRules $livewire) => $livewire->rememberVersion($record))
                        ->action(fn (RewardRule $record, ListRewardRules $livewire) => $livewire->removeRule($record)),
                ])->label('إجراءات المكافأة'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListRewardRules::route('/'), 'create' => RewardRuleResource\Pages\CreateRewardRule::route('/create'),
            'edit' => RewardRuleResource\Pages\EditRewardRule::route('/{record}/edit')];
    }
}
