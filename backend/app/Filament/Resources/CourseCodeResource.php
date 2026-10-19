<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Resources\CourseCodeResource\Pages\ListCourseCodes;
use App\Models\CourseCode;
use App\Services\AdminCourseCodeReadService;
use App\Support\BusinessClock;
use App\Support\UnicodeText;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

/** @extends DomainResource<CourseCode> */
final class CourseCodeResource extends DomainResource
{
    public const STATES = ['active' => 'مفعّل', 'inactive' => 'موقوف', 'expired' => 'انتهت صلاحيته', 'not_yet_active' => 'لم يبدأ بعد'];

    protected static bool $isDiscovered = true;

    protected static ?string $model = CourseCode::class;

    protected static ?string $modelLabel = 'كود منحة';

    protected static ?string $pluralModelLabel = 'أكواد المنح';

    protected static string|\UnitEnum|null $navigationGroup = 'التجارة';

    protected static ?int $navigationSort = 50;

    protected static string $permissionRoute = 'admin.course-codes';

    public static function draftFields(): array
    {
        return ['name', 'course_id', 'max_uses', 'number_of_codes', 'start_date', 'expiry_date', 'description', 'is_grant', 'allowed_email_domains'];
    }

    public static function allows(string $action, string $method = 'POST'): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.course-codes.'.$action, $method);
    }

    public static function getEloquentQuery(): Builder
    {
        return app(AdminCourseCodeReadService::class)->query([]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            self::draftRecovery(),
            Hidden::make('type')->default('course')->required()->in(['course']),
            Section::make('إتاحة الكورس')->columns(2)->schema([
                TextInput::make('name')->label('اسم الدفعة')->maxLength(255)
                    ->mutateStateForValidationUsing(fn ($state) => UnicodeText::clean($state, false))
                    ->dehydrateStateUsing(fn ($state) => UnicodeText::clean($state, false)),
                Select::make('course_id')->label('الكورس')->required()->searchable()
                    ->options(fn (): array => app(AdminCourseCodeReadService::class)->courseOptions()->mapWithKeys(fn ($course): array => [$course->id => $course->title])->all())
                    ->rules(['integer', Rule::exists('courses', 'id')]),
                TextInput::make('number_of_codes')->label('عدد الأكواد')->default(1)->required()->integer()->minValue(1)->maxValue(100)->visibleOn('create')->dehydrated(fn (string $operation): bool => $operation === 'create'),
                TextInput::make('max_uses')->label('عدد المستخدمين لكل كود')->default(1)->required()->integer()
                    ->minValue(fn (?CourseCode $record): int => max(1, (int) ($record->used_count ?? 0)))->maxValue(10000),
                Toggle::make('is_grant')->label('منحة جهة')->default(false)
                    ->helperText('يُتاح أقل اشتراك في الكورس حسب مزاياه المحددة'),
                Toggle::make('is_active')->label('مفعّل')->hiddenOn('create')->dehydrated(fn (string $operation): bool => $operation !== 'create'),
                Textarea::make('allowed_email_domains')->label('نطاقات البريد المسموح بها')->maxLength(2000)->rows(2)->columnSpanFull()
                    ->helperText('اتركها فارغة للجميع أو افصل النطاقات بفاصلة')->extraInputAttributes(['dir' => 'ltr']),
            ])->columnSpanFull(),
            Section::make('التوقيت والملاحظات')->columns(2)->schema([
                // Local wall time is converted exactly once by CourseCodeFormData.
                TextInput::make('start_date')->label('يبدأ بتوقيت القاهرة')->type('datetime-local')
                    ->rules(fn (string $operation): array => ['nullable', 'date', ...($operation === 'create' ? ['after_or_equal:'.BusinessClock::now()->startOfMinute()->format('Y-m-d\TH:i')] : [])]),
                TextInput::make('expiry_date')->label('ينتهي بتوقيت القاهرة')->type('datetime-local')
                    ->rules(fn (Get $get): array => ['nullable', 'date', ...(filled($get('start_date')) ? ['after:data.start_date'] : [])]),
                Textarea::make('description')->label('ملاحظات داخلية')->maxLength(1000)->rows(3)->columnSpanFull()
                    ->mutateStateForValidationUsing(fn ($state) => UnicodeText::clean($state))
                    ->dehydrateStateUsing(fn ($state) => UnicodeText::clean($state)),
            ])->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('الكود والإتاحة')->columns(2)->schema([
                TextEntry::make('code')->label('الكود')->copyable()->formatStateUsing(fn (string $state): HtmlString => self::leftToRight($state)),
                TextEntry::make('name')->label('الدفعة')->placeholder('بدون اسم'),
                TextEntry::make('target')->label('المحتوى')->state(fn (CourseCodeResource\Pages\ViewCourseCode $livewire): string => $livewire->targetName())->columnSpanFull(),
                TextEntry::make('type')->label('النوع')->formatStateUsing(fn (string $state): string => ['course' => 'كورس', 'lesson' => 'مقطع قديم', 'multiple_lessons' => 'مقاطع متعددة قديمة'][$state]),
                TextEntry::make('is_active')->label('الحالة')->formatStateUsing(fn (CourseCode $record): string => self::STATES[self::state($record)])->badge(),
                TextEntry::make('used_count')->label('استخدامات مسجلة')->numeric(),
                TextEntry::make('max_uses')->label('الحد الكلي للمستخدمين')->numeric(),
                TextEntry::make('institutional')->label('منحة جهة')->state(fn (CourseCode $record): string => $record->isInstitutionalGrant() ? 'نعم' : 'لا'),
                TextEntry::make('allowed_email_domains')->label('نطاقات البريد')->listWithLineBreaks()->placeholder('كل النطاقات')
                    ->formatStateUsing(fn (string $state): HtmlString => self::leftToRight($state)),
            ])->columnSpanFull(),
            Section::make('التوقيت والملاحظات')->columns(2)->schema([
                self::dateEntry('start_date', 'البداية بتوقيت القاهرة', 'فور التفعيل'),
                self::dateEntry('expiry_date', 'الانتهاء بتوقيت القاهرة', 'بلا نهاية'),
                self::dateEntry('created_at', 'أُنشئ بتوقيت القاهرة'),
                self::dateEntry('updated_at', 'آخر تعديل بتوقيت القاهرة'),
                TextEntry::make('description')->label('ملاحظات داخلية')->placeholder('لا توجد ملاحظات')->columnSpanFull(),
            ])->columnSpanFull(),
        ]);
    }

    public static function state(CourseCode $record): string
    {
        return ! $record->is_active ? 'inactive' : ($record->is_expired ? 'expired' : ($record->is_not_yet_active ? 'not_yet_active' : 'active'));
    }

    private static function dateEntry(string $field, string $label, string $placeholder = 'غير مسجل'): TextEntry
    {
        return TextEntry::make($field)->label($label)->placeholder($placeholder)
            ->formatStateUsing(fn ($state): HtmlString => self::leftToRight(BusinessClock::format($state, 'Y-m-d H:i')));
    }

    private static function leftToRight(string $text): HtmlString
    {
        return new HtmlString('<bdi dir="ltr">'.e($text).'</bdi>');
    }

    public static function getRelations(): array
    {
        return [CourseCodeResource\RelationManagers\UsagesRelationManager::class];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('الدفعة')->placeholder('بدون اسم')->wrap()
                ->description(fn (CourseCode $record): HtmlString => new HtmlString('<bdi dir="ltr">'.e($record->code).'</bdi>')),
            TextColumn::make('code')->label('الكود')->copyable()->extraAttributes(['dir' => 'ltr'])->visibleFrom('md'),
            TextColumn::make('target')->label('المحتوى')->state(fn (CourseCode $record): string => match ($record->type) {
                'course' => $record->course?->title ?: 'كورس غير متاح', 'lesson' => $record->lesson?->title ?: 'مقطع غير متاح', 'multiple_lessons' => 'مقاطع متعددة قديمة',
            })->wrap(),
            TextColumn::make('used_count')->label('الاستخدام')->numeric()->description(fn (CourseCode $record): string => 'من '.$record->max_uses),
            TextColumn::make('state')->label('الحالة')->state(fn (CourseCode $record): string => self::state($record))
                ->formatStateUsing(fn (string $state): string => self::STATES[$state])->badge()->visibleFrom('md'),
            TextColumn::make('expiry_date')->label('الانتهاء بتوقيت القاهرة')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName())->placeholder('بلا نهاية')->visibleFrom('lg'),
        ])->searchable(false)->filters([Filter::make('criteria')->schema([
            TextInput::make('code')->label('الكود')->maxLength(120)->extraInputAttributes(['dir' => 'ltr']),
            TextInput::make('name')->label('اسم الدفعة')->maxLength(255),
            Select::make('course_id')->label('الكورس')->searchable()->options(fn (): array => app(AdminCourseCodeReadService::class)->courseOptions()->mapWithKeys(fn ($course): array => [$course->id => $course->title])->all()),
            Select::make('status')->label('الحالة')->options(self::STATES),
            DatePicker::make('start_date')->label('يوم البداية بتوقيت القاهرة')->native(),
            DatePicker::make('expiry_date')->label('يوم الانتهاء بتوقيت القاهرة')->native(),
            Select::make('type')->label('النوع')->options(['course' => 'كورس', 'lesson' => 'مقطع قديم', 'multiple_lessons' => 'مقاطع متعددة قديمة']),
            TextInput::make('lesson_id')->label('رقم المقطع القديم')->integer()->minValue(1),
        ])->indicateUsing(fn (array $data): array => self::indicators($data))
            ->query(fn (Builder $query, array $data): Builder => app(AdminCourseCodeReadService::class)->applyFilters($query, $data))], layout: FiltersLayout::Modal)
            ->filtersFormColumns(2)->filtersTriggerAction(fn (Action $action): Action => $action->stickyModalHeader()->stickyModalFooter())
            ->defaultSort('id', 'desc')->defaultPaginationPageOption(25)->poll('30s')->emptyStateHeading('لا توجد أكواد بهذه التصفيات')->emptyStateDescription('')
            ->recordUrl(null)->recordActions([ActionGroup::make([
                Action::make('view')->label('التفاصيل')->url(fn (CourseCode $record): string => self::getUrl('view', ['record' => $record->id])),
                Action::make('edit')->label('تعديل')->authorize(fn (CourseCode $record): bool => $record->type === 'course' && self::canEdit($record))->url(fn (CourseCode $record): string => self::getUrl('edit', ['record' => $record->id])),
                Action::make('remove')->label('حذف')->color('danger')->requiresConfirmation()->authorize(fn (CourseCode $record): bool => self::canDelete($record))
                    ->modalDescription('الكود المستخدم يُوقَف مع الاحتفاظ بسجله')
                    ->mountUsing(fn (CourseCode $record, ListCourseCodes $livewire) => $livewire->remember(collect([$record]), 'remove'))
                    ->action(fn (CourseCode $record, ListCourseCodes $livewire) => $livewire->remove($record)),
            ])->label('إجراءات الكود')])->toolbarActions([BulkActionGroup::make(array_map(fn (string $operation): BulkAction => BulkAction::make($operation)
            ->label(['activate' => 'تفعيل المحدد', 'deactivate' => 'إيقاف المحدد', 'delete' => 'حذف المحدد'][$operation])
            ->color($operation === 'delete' ? 'danger' : 'primary')->requiresConfirmation()->authorize(fn (): bool => self::allows('bulk-action'))
            ->modalDescription($operation === 'delete' ? 'الأكواد المستخدمة تُوقَف مع الاحتفاظ بسجلها' : 'يُطبّق الإجراء على الأكواد المحددة')
            ->mountUsing(fn (Collection $records, ListCourseCodes $livewire) => $livewire->remember($records, $operation))
            ->action(fn (ListCourseCodes $livewire) => $livewire->applyBulk($operation))->deselectRecordsAfterCompletion(), ['activate', 'deactivate', 'delete']))]);
    }

    private static function indicators(array $data): array
    {
        $labels = ['code' => 'الكود', 'name' => 'الدفعة', 'course_id' => 'الكورس', 'status' => 'الحالة', 'start_date' => 'يوم البداية', 'expiry_date' => 'يوم الانتهاء', 'type' => 'النوع', 'lesson_id' => 'المقطع'];

        return collect(array_filter($data, fn ($value): bool => filled($value)))->map(fn ($value, string $key): string => ($labels[$key] ?? $key).': '.($key === 'status' ? (self::STATES[$value] ?? $value) : $value))->values()->all();
    }

    public static function listUrl(array $filters = []): string
    {
        return self::getUrl('index', ['filters' => ['criteria' => $filters]]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCourseCodes::route('/'),
            'create' => CourseCodeResource\Pages\CreateCourseCode::route('/create'),
            'view' => CourseCodeResource\Pages\ViewCourseCode::route('/{record}'),
            'edit' => CourseCodeResource\Pages\EditCourseCode::route('/{record}/edit')];
    }
}
