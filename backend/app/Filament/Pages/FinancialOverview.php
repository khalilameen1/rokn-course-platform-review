<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\ProjectSubmissionResource;
use App\Filament\Support\FinancialOverviewSchema;
use App\Services\AdminHomeReadService;
use App\Support\ReportPeriod;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/** @property-read Schema $form @property-read Schema $infolist */
final class FinancialOverview extends Page
{
    protected static ?string $title = 'الأداء المالي';

    protected static string|\UnitEnum|null $navigationGroup = 'التجارة';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.financial-overview';

    #[Url]
    public array $filters = [];

    #[Locked]
    public string $appliedPeriod = '30d';

    private ?array $cachedReport = null;

    public static function canAccess(): bool
    {
        return app(AdminPermissionMatrix::class)->allowsCapability(auth('web')->user(), AdminPermissionMatrix::FINANCIAL_REPORTS);
    }

    public function mount(): void
    {
        $this->form->fill($this->filters + ['period' => '30d']);
        try {
            $this->applyReportFilters();
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('filters')->components([
            Select::make('period')->label('الفترة')->options(ReportPeriod::labels())->required(),
        ]);
    }

    public function applyReportFilters(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->appliedPeriod = $this->form->getState()['period'];
        $this->cachedReport = null;
        $this->cacheSchema('infolist', null);
    }

    public function report(): array
    {
        return $this->cachedReport ??= app(AdminHomeReadService::class)->read(ReportPeriod::fromKey($this->appliedPeriod));
    }

    public function infolist(Schema $schema): Schema
    {
        return FinancialOverviewSchema::configure($schema, $this->report());
    }

    public function getSubheading(): string
    {
        return $this->report()['period']->description();
    }

    protected function getHeaderActions(): array
    {
        return [ActionGroup::make([
            Action::make('operations')->label('حالة النشر')->url(ProductOperations::getUrl()),
            Action::make('playback')->label('الوسائط')->url(PlaybackOperations::getUrl()),
            Action::make('projects')->label('المشاريع')->url(ProjectSubmissionResource::getUrl()),
            Action::make('payments')->label('المدفوعات المعلقة')->url(OrderResource::getUrl()),
        ])->label('متابعة التشغيل')->button()];
    }
}
