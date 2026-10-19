<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Resources\OperatingCostResource;
use App\Filament\Support\CommercialReportSchema;
use App\Services\AdminOperatingCostReadService;
use App\Services\PlatformCommercialReportService;
use App\Support\ReportPeriod;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/** @property-read Schema $form @property-read Schema $infolist */
final class CommercialReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'اقتصاديات التشغيل';

    protected static string|\UnitEnum|null $navigationGroup = 'التجارة';

    protected static ?int $navigationSort = 45;

    protected string $view = 'filament.pages.commercial-report';

    #[Url]
    public array $filters = [];

    #[Locked]
    public array $appliedFilters = ['period' => 'all'];

    private ?array $cachedReport = null;

    public static function canAccess(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.operating-costs.report', 'GET');
    }

    public function mount(): void
    {
        $this->form->fill($this->filters + ['period' => 'all']);
        try {
            $this->setReportScope();
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('filters')->components([
            Section::make('نطاق التقرير')->columns(['default' => 1, 'md' => 2, 'xl' => 3])->schema([
                Select::make('period')->label('الفترة')->options(ReportPeriod::labels())->required(),
                TextInput::make('q')->label('طالب أو كورس')->maxLength(160),
                Select::make('course_id')->label('الكورس')->placeholder('كل الكورسات')->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => app(AdminOperatingCostReadService::class)->courseOptions($search))
                    ->getOptionLabelUsing(fn ($value): ?string => app(AdminOperatingCostReadService::class)->courseLabel($value))
                    ->rules(['nullable', 'integer', 'exists:courses,id']),
                Select::make('plan')->label('الفئة الحالية')->placeholder('كل الفئات')->searchable()->options(fn (): array => $this->report()['filter_options']['plans']
                    ->mapWithKeys(fn (array $plan): array => [$plan['code'] !== '' ? $plan['code'] : $plan['name'] => $plan['name']])->all())->rules(['nullable', 'string', 'max:100']),
                Select::make('source')->label('مصدر الإتاحة الحالي')->placeholder('كل المصادر')->options([
                    'purchase' => 'شراء', 'grant' => 'منحة', 'course_code' => 'كود كورس', 'grant_plus_purchase' => 'منحة مع شراء', 'code_plus_purchase' => 'كود مع شراء',
                ]),
            ]),
        ]);
    }

    public function applyReportFilters(): void
    {
        $this->setReportScope();
        $this->resetPage();
        $this->flushCachedTableRecords();
    }

    private function setReportScope(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->appliedFilters = $this->form->getState();
        $this->cachedReport = null;
        $this->cacheSchema('infolist', null);
    }

    public function report(): array
    {
        return $this->cachedReport ??= app(PlatformCommercialReportService::class)->report($this->appliedFilters);
    }

    public function infolist(Schema $schema): Schema
    {
        return CommercialReportSchema::configure($schema, $this->report());
    }

    public function getSubheading(): string
    {
        return $this->report()['period']->description();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invoices')->label('فواتير الخدمات')->url(OperatingCostResource::getUrl()),
            Action::make('export')->label('تصدير الطلاب CSV')->url(fn (): string => route('admin.operating-costs.report.export', $this->appliedFilters))
                ->authorize(fn (): bool => app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.operating-costs.report.export', 'GET')),
        ];
    }

    public function table(Table $table): Table
    {
        return $table->heading('استهلاك ودخل الطلاب خلال الفترة')->records(function (int $page, int $recordsPerPage): LengthAwarePaginator {
            $rows = $this->report()['student_rows']->mapWithKeys(fn (array $row, int $index): array => [$index + 1 => CommercialReportSchema::student($row)]);

            return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage), $rows->count(), $recordsPerPage, $page);
        })->paginated([20, 30, 50, 100])->defaultPaginationPageOption(30)->poll(null)->columns([
            TextColumn::make('name')->label('الطالب')->wrap(),
            TextColumn::make('email')->label('البريد')->wrap()->visibleFrom('md'),
            TextColumn::make('net_egp')->label('صافي الدخل')->visibleFrom('md'),
            TextColumn::make('service_cost_egp')->label('تكلفة الخدمات')->visibleFrom('lg'),
            TextColumn::make('margin_egp')->label('الهامش')->visibleFrom('lg'),
        ])->recordActions([
            Action::make('details')->label('التفاصيل')->modalHeading('استهلاك الطالب خلال الفترة')->modalSubmitAction(false)->modalCancelActionLabel('إغلاق')
                ->schema(fn (array $record): array => CommercialReportSchema::studentFields($record)),
        ])->emptyStateHeading('لا توجد نتائج مطابقة')->emptyStateDescription('');
    }
}
