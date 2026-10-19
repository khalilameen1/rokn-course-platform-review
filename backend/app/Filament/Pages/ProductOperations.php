<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Support\ProductOperationsSchema;
use App\Models\Course;
use App\Services\AdminProductOperationsReadService;
use App\Services\MediaReconciliationService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;

/** @property-read Schema $infolist */
final class ProductOperations extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'تشغيل المنتج';

    protected static string|\UnitEnum|null $navigationGroup = 'التشغيل';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.product-operations';

    private ?array $cachedReport = null;

    public static function canAccess(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.product-operations.index', 'GET');
    }

    public function report(): array
    {
        return $this->cachedReport ??= app(AdminProductOperationsReadService::class)->report();
    }

    public function infolist(Schema $schema): Schema
    {
        return ProductOperationsSchema::configure($schema, $this->report());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('switches')->label('مفاتيح التشغيل')->url(ProductFeatureSwitches::getUrl()),
            Action::make('probe')->label('فحص مقطع')->authorize(fn (): bool => $this->canProbe())
                ->modalHeading('فحص الفيديو لدى مزود البث')->schema([
                    Select::make('lesson_id')->label('المقطع الذي يحتاج متابعة')->required()->searchable()
                        ->options(fn (): array => $this->report()['mediaAttention']->mapWithKeys(fn ($lesson): array => [$lesson->id => $lesson->title])->all()),
                ])->action(function (array $data): void {
                    abort_unless(self::canAccess() && $this->canProbe(), 403);
                    $lesson = $this->report()['mediaAttention']->firstWhere('id', (int) $data['lesson_id']);
                    abort_unless($lesson !== null, 404);
                    $result = app(MediaReconciliationService::class)->reconcileLesson($lesson, true, true);
                    $playable = ($result['playback_status'] ?? null) === 'ready';
                    $healthy = ($result['integrity_status'] ?? null) === 'healthy';
                    $notification = Notification::make()->title($playable
                        ? ($healthy ? 'الفيديو جاهز للتشغيل' : 'الفيديو يعمل لكن توجد تفاصيل تحتاج مراجعة')
                        : 'الفيديو لم يجهز للمشاهدة بعد');
                    ($playable && $healthy ? $notification->success() : $notification->warning())->send();
                    $this->cachedReport = null;
                    $this->cacheSchema('infolist', null);
                    $this->flushCachedTableRecords();
                }),
        ];
    }

    private function canProbe(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.media-health.probe', 'POST');
    }

    public function table(Table $table): Table
    {
        return $table->heading('الكورسات كما يديرها المنتج')->records(function (?string $search, int $page, int $recordsPerPage): LengthAwarePaginator {
            // Display-only projection; never serialize private course configuration into table records.
            $rows = $this->report()['courses']->filter(fn (Course $course): bool => blank($search) || mb_stripos($course->name_ar.' '.$course->name_en, $search) !== false)
                ->mapWithKeys(fn (Course $course): array => [$course->id => [
                    'id' => $course->id, 'name' => $course->name_ar ?: $course->name_en,
                    'state' => ($course->is_coming_soon ? ($course->is_catalog_visible ? 'قريبًا ظاهر' : 'مسودة مخفية') : 'منشور').($course->is_main_course ? ' · الكورس الرئيسي' : ''),
                    'price' => (int) $course->price === 0 ? 'مجاني' : number_format($course->price).' عملة',
                    'map' => number_format($course->modules_count).' وحدة · '.number_format($course->sections_count).' عنصرًا',
                    'students' => $course->active_enrollments_count,
                    'rating' => $course->ratings_count ? number_format((float) $course->getAttribute('ratings_avg_rating'), 1).' / ٥ · '.$course->ratings_count.' تقييم' : 'لا يوجد تقييم',
                    'coins' => number_format((int) $course->getAttribute('paid_coins_spent')).' / '.number_format((int) $course->getAttribute('reward_coins_spent')),
                    'incomplete' => (int) $course->getAttribute('coin_ledger_incomplete_orders'),
                    'ai' => (int) $course->getAttribute('ai_plans_count') > 0 ? 'حسب الفئة' : 'غير مشمول',
                ]]);

            return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage), $rows->count(), $recordsPerPage, $page);
        })->searchable()->searchPlaceholder('اسم الكورس')->paginated([10, 25, 50])->defaultPaginationPageOption(10)->poll(null)->columns([
            TextColumn::make('name')->label('الكورس')->wrap()->description(fn (array $record): string => $record['state'].' · '.$record['price']),
            TextColumn::make('map')->label('الخريطة')->visibleFrom('md'),
            TextColumn::make('students')->label('الطلاب')->numeric()->visibleFrom('md'),
            TextColumn::make('rating')->label('التقييم')->visibleFrom('lg'),
            TextColumn::make('coins')->label('مدفوعة / مكافآت')->visibleFrom('lg')->description(fn (array $record): string => $record['incomplete'] > 0 ? $record['incomplete'].' غير مكتملة' : ''),
            TextColumn::make('ai')->label('Rokn AI')->visibleFrom('lg'),
        ])->recordActions([ActionGroup::make([
            Action::make('view')->label('عرض')->url(fn (array $record): string => route('admin.courses.show', $record['id']))
                ->authorize(fn (): bool => app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.courses.show', 'GET')),
            Action::make('details')->label('المؤشرات')->modalHeading('مؤشرات الكورس')->modalSubmitAction(false)->modalCancelActionLabel('إغلاق')
                ->fillForm(fn (array $record): array => $record)->schema(ProductOperationsSchema::courseFields()),
        ])->label('إجراءات الكورس')])->emptyStateHeading('لا توجد كورسات مطابقة')->emptyStateDescription('');
    }
}
