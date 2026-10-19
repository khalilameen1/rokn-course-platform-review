<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Support\ProductAnalyticsSchema;
use App\Services\AdminProductAnalyticsReadService;
use App\Support\ReportPeriod;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/** @property-read Schema $form @property-read Schema $infolist */
final class ProductAnalytics extends Page
{
    protected static ?string $title = 'تحليلات المنتج';

    protected static string|\UnitEnum|null $navigationGroup = 'التشغيل';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.product-analytics';

    #[Url]
    public array $filters = [];

    #[Locked]
    public array $appliedFilters = ['period' => '30d', 'course_id' => null];

    private ?array $cachedReport = null;

    public static function canAccess(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.product-analytics.index', 'GET');
    }

    public function mount(): void
    {
        $this->form->fill($this->filters + ['period' => '30d', 'course_id' => null]);
        try {
            $this->applyReportFilters();
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('filters')->components([
            Section::make('نطاق التقرير')->columns(2)->schema([
                Select::make('period')->label('الفترة')->options(ReportPeriod::labels())->required()->rules(['string']),
                Select::make('course_id')->label('الكورس')->placeholder('كل المنصة')->searchable()
                    ->options(fn (): array => app(AdminProductAnalyticsReadService::class)->courses()->mapWithKeys(fn ($course): array => [$course->id => $course->name_ar ?: $course->name_en])->all())
                    ->rules(['nullable', 'integer', 'exists:courses,id']),
            ]),
        ]);
    }

    public function applyReportFilters(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->appliedFilters = $this->form->getState();
        $this->cachedReport = null;
        // The canonical identity may differ from an archived revision bookmark.
        $this->appliedFilters = $this->report()['filters'];
        $this->cacheSchema('infolist', null);
    }

    public function report(): array
    {
        return $this->cachedReport ??= app(AdminProductAnalyticsReadService::class)->report(
            filled($this->appliedFilters['course_id'] ?? null) ? (int) $this->appliedFilters['course_id'] : null,
            $this->appliedFilters['period'],
        );
    }

    public function infolist(Schema $schema): Schema
    {
        return ProductAnalyticsSchema::configure($schema, $this->report());
    }

    public function getSubheading(): string
    {
        return $this->report()['period']->description();
    }
}
