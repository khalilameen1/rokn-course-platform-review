<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Filament\Widgets\OrderChannelReport;
use App\Filament\Widgets\OrderSummary;
use App\Services\AdminPaymentOperationsReadService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/** @property-read Schema $form */
final class PaymentReport extends Page
{
    protected static string $resource = OrderResource::class;

    protected static ?string $title = 'تقارير التحصيل';

    protected string $view = 'filament.orders.report';

    #[Url]
    public array $filters = [];

    #[Locked]
    public array $appliedFilters = [];

    private ?array $cachedReport = null;

    public function mount(): void
    {
        $this->form->fill($this->filters);
        try {
            $this->applyReportFilters();
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('filters')->components([
            Section::make('تصفية التقرير')->collapsible()->collapsed()->columns(2)->schema([
                TextInput::make('search')->label('رقم الطلب أو المرجع أو الطالب')->maxLength(1000),
                ...OrderResource::filterSchema(),
            ])->footerActions([Action::make('apply')->label('تطبيق التصفيات')->submit('applyReportFilters')]),
        ]);
    }

    public function applyReportFilters(): void
    {
        $this->appliedFilters = array_filter($this->form->getState(), fn ($value): bool => $value !== null && $value !== '');
        $this->cachedReport = null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('orders')->label('قائمة الطلبات')->url(fn (): string => OrderResource::listUrl($this->appliedFilters)),
            Action::make('reconciliation')->label('مراجعة التسويات')->url(route('admin.payment-reconciliation-findings.index')),
            Action::make('export')->label('تصدير CSV')->url(fn (): string => route('admin.orders.export', $this->appliedFilters)),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [OrderSummary::class, OrderChannelReport::class];
    }

    public function getWidgetData(): array
    {
        if ($this->cachedReport === null) {
            $this->cachedReport = app(AdminPaymentOperationsReadService::class)->report($this->appliedFilters);
            $this->cachedReport['paymentChannelReport']['rows'] = $this->cachedReport['paymentChannelReport']['rows']->all();
        }

        return ['report' => $this->cachedReport, 'filters' => $this->appliedFilters];
    }
}
