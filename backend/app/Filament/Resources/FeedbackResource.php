<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\FeedbackReport;
use App\Services\AdminSupportCaseReadService;
use App\Support\BusinessClock;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/** @extends ReadResource<FeedbackReport> */
final class FeedbackResource extends ReadResource
{
    public const STATUSES = ['new' => 'جديد', 'reviewing' => 'قيد المراجعة', 'waiting_for_user' => 'بانتظار الطالب',
        'resolved' => 'محلول', 'closed' => 'مغلق', 'dismissed' => 'مغلق قديم'];

    public const CATEGORIES = ['bug' => 'مشكلة', 'suggestion' => 'اقتراح', 'course_content' => 'محتوى', 'playback' => 'تشغيل'];

    public const PRIORITIES = ['low' => 'منخفضة', 'normal' => 'عادية', 'high' => 'عالية', 'urgent' => 'عاجلة'];

    protected static bool $isDiscovered = true;

    protected static string|\UnitEnum|null $navigationGroup = 'المراجعة';

    protected static ?int $navigationSort = 30;

    protected static ?string $model = FeedbackReport::class;

    protected static ?string $slug = 'feedback';

    protected static ?string $modelLabel = 'رسالة دعم';

    protected static ?string $pluralModelLabel = 'رسائل الدعم';

    protected static string $legacyListRoute = 'admin.feedback.index';

    public static function getEloquentQuery(): Builder
    {
        return app(AdminSupportCaseReadService::class)->query([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('public_id')->label('البلاغ')->formatStateUsing(fn (string $state): string => strtoupper(substr($state, -8)))
                ->description(fn (FeedbackReport $record): string => (in_array($record->priority, ['high', 'urgent'], true)
                    ? 'أولوية '.self::PRIORITIES[$record->priority].' — ' : '').Str::limit($record->message, 85))->wrap(),
            TextColumn::make('category')->label('النوع')->formatStateUsing(fn (string $state): string => self::CATEGORIES[$state] ?? $state)->visibleFrom('md'),
            TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state),
            TextColumn::make('priority')->label('الأولوية')->formatStateUsing(fn (string $state): string => self::PRIORITIES[$state] ?? $state)->badge()
                ->color(fn (string $state): string => match ($state) {
                    'urgent' => 'danger', 'high' => 'warning', default => 'gray'
                })->visibleFrom('md'),
            TextColumn::make('assignee.name')->label('المسؤول')->placeholder('غير مسند')->visibleFrom('md'),
            TextColumn::make('response')->label('موعد الاستجابة')->visibleFrom('md')
                ->state(fn (FeedbackReport $record): string => $record->getAttribute('last_staff_message_at') ? 'تم الرد'
                    : ($record->getAttribute('first_response_due_at') ? BusinessClock::relative($record->getAttribute('first_response_due_at')) : 'لم يُحدد موعد')),
            TextColumn::make('updated_at')->label('آخر تحديث بتوقيت القاهرة')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName())->visibleFrom('md'),
        ])->searchable(false)->filters([
            Filter::make('criteria')->schema([
                TextInput::make('q')->label('رقم الحالة أو الرسالة أو الطالب')->maxLength(120),
                Select::make('status')->label('الحالة')->options(self::STATUSES),
                Select::make('category')->label('النوع')->options(self::CATEGORIES),
                Select::make('priority')->label('الأولوية')->options(self::PRIORITIES),
                Select::make('assigned_to')->label('المسؤول')->searchable()->options(fn (): array => app(AdminSupportCaseReadService::class)->assignees()
                    ->mapWithKeys(fn ($user): array => [$user->id => $user->getFilamentName()])->all()),
                Select::make('overdue')->label('الاستجابة')->options(['1' => 'تجاوز الموعد']),
                TextInput::make('app_version')->label('إصدار التطبيق')->maxLength(32)->extraInputAttributes(['dir' => 'ltr']),
                TextInput::make('course_id')->label('رقم الكورس')->integer(),
                DatePicker::make('from')->label('من')->native(),
                DatePicker::make('to')->label('إلى')->native()->afterOrEqual(fn (Get $get): string => filled($get('from')) ? 'from' : ''),
            ])->indicateUsing(fn (array $data): array => self::indicators($data))
                ->query(fn (Builder $query, array $data): Builder => $query->whereIn('feedback_reports.id', app(AdminSupportCaseReadService::class)->query($data)->select('feedback_reports.id'))),
        ], layout: FiltersLayout::Modal)->filtersFormColumns(2)->defaultSort(fn (Builder $query): Builder => $query->latest('updated_at')->latest('id'))->defaultPaginationPageOption(30)
            ->paginated([10, 30, 50, 100])->poll('30s')->emptyStateHeading('لا توجد حالات مطابقة')->emptyStateDescription('')
            ->recordUrl(fn (FeedbackReport $record): string => route('admin.feedback.show', $record))
            ->recordActions([Action::make('open')->label('عرض')->url(fn (FeedbackReport $record): string => route('admin.feedback.show', $record))]);
    }

    private static function indicators(array $data): array
    {
        $labels = ['q' => 'البحث', 'status' => 'الحالة', 'category' => 'النوع', 'priority' => 'الأولوية', 'assigned_to' => 'المسؤول',
            'overdue' => 'الاستجابة', 'app_version' => 'الإصدار', 'course_id' => 'الكورس', 'from' => 'من', 'to' => 'إلى'];
        $options = ['status' => self::STATUSES, 'category' => self::CATEGORIES, 'priority' => self::PRIORITIES, 'overdue' => ['1' => 'تجاوز الموعد']];

        return collect(array_filter($data, fn ($value): bool => filled($value)))
            ->map(fn ($value, string $key): string => ($labels[$key] ?? $key).': '.($options[$key][$value] ?? $value))->values()->all();
    }

    public static function getPages(): array
    {
        return ['index' => FeedbackResource\Pages\ListFeedback::route('/')];
    }
}
