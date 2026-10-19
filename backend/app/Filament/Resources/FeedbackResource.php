<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Resources\FeedbackResource\Pages\ViewFeedback;
use App\Models\FeedbackReport;
use App\Services\AdminSupportCaseReadService;
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
use Illuminate\Support\Str;

/** @extends ReadResource<FeedbackReport> */
final class FeedbackResource extends ReadResource
{
    public const STATUSES = ['new' => 'جديد', 'reviewing' => 'قيد المراجعة', 'waiting_for_user' => 'بانتظار الطالب',
        'resolved' => 'محلول', 'closed' => 'مغلق', 'dismissed' => 'مغلق قديم'];

    public const CATEGORIES = ['bug' => 'مشكلة', 'suggestion' => 'اقتراح', 'course_content' => 'محتوى', 'playback' => 'تشغيل'];

    public const PRIORITIES = ['low' => 'منخفضة', 'normal' => 'عادية', 'high' => 'عالية', 'urgent' => 'عاجلة'];

    public const RESOLUTIONS = ['fixed' => 'تم الإصلاح', 'guidance' => 'تم الإرشاد', 'compensated' => 'تم التعويض',
        'not_reproducible' => 'تعذر تكرار المشكلة', 'duplicate' => 'بلاغ مكرر'];

    protected static bool $isDiscovered = true;

    protected static string|\UnitEnum|null $navigationGroup = 'المراجعة';

    protected static ?int $navigationSort = 30;

    protected static ?string $model = FeedbackReport::class;

    protected static ?string $slug = 'feedback';

    protected static ?string $modelLabel = 'رسالة دعم';

    protected static ?string $pluralModelLabel = 'رسائل الدعم';

    protected static string $legacyListRoute = 'admin.feedback.index';

    public static function allows(string $action, string $method = 'POST'): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.feedback.'.$action, $method);
    }

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
            ->recordActions([ViewAction::make()]);
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
        return ['index' => FeedbackResource\Pages\ListFeedback::route('/'), 'view' => ViewFeedback::route('/{record}')];
    }

    public static function infolist(Schema $schema): Schema
    {
        $attachments = fn (string $name): RepeatableEntry => RepeatableEntry::make($name)->hiddenLabel()->schema([
            TextEntry::make('url')->label('الصورة')->hiddenLabel()->placeholder('الصورة غير متاحة')
                ->formatStateUsing(fn (): string => 'عرض الصورة')->url(fn (?string $state): ?string => $state)->openUrlInNewTab(),
        ]);

        return $schema->components([
            Section::make('المحادثة')->schema([
                RepeatableEntry::make('conversation')->hiddenLabel()->state(fn (ViewFeedback $livewire): array => $livewire->conversation())->columns(2)->schema([
                    TextEntry::make('kind')->hiddenLabel()->badge(),
                    TextEntry::make('sent_at')->hiddenLabel()->extraAttributes(['dir' => 'ltr']),
                    TextEntry::make('body')->hiddenLabel()->columnSpanFull(),
                    $attachments('attachments')->columnSpanFull()->visible(fn (RepeatableEntry $component): bool => filled($component->getState())),
                ]),
            ])->columnSpanFull(),
            Section::make('الصور الأصلية')->visible(fn (ViewFeedback $livewire): bool => $livewire->originalAttachments() !== [])->schema([
                $attachments('original_attachments')->state(fn (ViewFeedback $livewire): array => $livewire->originalAttachments()),
            ])->columnSpanFull(),
            Section::make('السياق')->columns(2)->schema([
                TextEntry::make('status')->label('الحالة')->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state)->badge(),
                TextEntry::make('priority')->label('الأولوية')->formatStateUsing(fn (string $state): string => self::PRIORITIES[$state] ?? $state),
                TextEntry::make('user.name')->label('الطالب')->placeholder('زائر'),
                TextEntry::make('email')->label('البريد')->state(fn (FeedbackReport $record) => $record->user?->email ?: $record->getAttribute('requester_email'))
                    ->placeholder('—')->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('course.name_ar')->label('الكورس')->placeholder('—'),
                TextEntry::make('order_id')->label('رقم الطلب')->placeholder('—'),
                TextEntry::make('assignee.name')->label('المسؤول')->placeholder('غير مسند'),
                TextEntry::make('resolution_kind')->label('نتيجة المعالجة')->formatStateUsing(fn (string $state): string => self::RESOLUTIONS[$state] ?? $state)->placeholder('—'),
                TextEntry::make('platform')->label('النظام')->placeholder('—'),
                TextEntry::make('app_version')->label('إصدار التطبيق')->placeholder('—'),
                TextEntry::make('build_number')->label('البناء')->placeholder('—'),
                TextEntry::make('first_response_due_at')->label('موعد أول رد')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName())->placeholder('—'),
            ])->columnSpanFull(),
            Section::make('تعويض عطل من طرفنا')->visible(fn (FeedbackReport $record): bool => $record->getAttribute('order_id') !== null)->schema([
                TextEntry::make('compensation')->hiddenLabel()->state(fn (ViewFeedback $livewire): string => $livewire->compensableCoins() > 0
                    ? 'الحد الأقصى المتاح للتعويض '.$livewire->compensableCoins().' عملة — تُضاف العملات إلى محفظة الطالب فور التأكيد'
                    : 'لا يتوفر تعويض لهذا الطلب أو استُنفد الحد المتاح'),
            ])->columnSpanFull(),
            Section::make('بلاغ أعمال')->visible(fn (ViewFeedback $livewire): bool => $livewire->portfolioOwner() !== null)->schema([
                TextEntry::make('portfolio_owner')->label('صاحب الأعمال')->state(fn (ViewFeedback $livewire): ?string => $livewire->portfolioOwner()?->name)
                    ->url(fn (ViewFeedback $livewire): ?string => $livewire->portfolioOwner() ? route('admin.portfolio-preview.show', $livewire->portfolioOwner()) : null)->openUrlInNewTab(),
            ])->columnSpanFull(),
            Section::make('سجل الحالة')->collapsible()->collapsed()->schema([
                RepeatableEntry::make('history')->hiddenLabel()->state(fn (ViewFeedback $livewire): array => $livewire->history())->columns(3)->schema([
                    TextEntry::make('event')->label('الإجراء'), TextEntry::make('actor')->label('المنفّذ'),
                    TextEntry::make('at')->label('بتوقيت القاهرة')->extraAttributes(['dir' => 'ltr']),
                ])->placeholder('لا توجد إجراءات مسجلة'),
            ])->columnSpanFull(),
        ]);
    }
}
