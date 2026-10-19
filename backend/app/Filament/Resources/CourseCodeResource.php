<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Resources\CourseCodeResource\Pages\ListCourseCodes;
use App\Models\CourseCode;
use App\Services\AdminCourseCodeReadService;
use App\Support\BusinessClock;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

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
            TextColumn::make('state')->label('الحالة')->state(fn (CourseCode $record): string => ! $record->is_active ? 'inactive' : ($record->is_expired ? 'expired' : ($record->is_not_yet_active ? 'not_yet_active' : 'active')))
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
                Action::make('view')->label('التفاصيل')->url(fn (CourseCode $record): string => route('admin.course-codes.show', $record)),
                Action::make('edit')->label('تعديل')->authorize(fn (CourseCode $record): bool => self::canEdit($record))->url(fn (CourseCode $record): string => route('admin.course-codes.edit', $record)),
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
        return ['index' => ListCourseCodes::route('/')];
    }
}
