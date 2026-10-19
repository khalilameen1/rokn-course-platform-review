<?php

namespace App\Filament\Resources\OperatingCostResource\Pages;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Resources\OperatingCostResource;
use App\Filament\Widgets\OperatingCostSummary;
use App\Models\OperatingCostPool;
use App\Services\AdminOperatingCostAuthoringService;
use App\Services\AdminOperatingCostReadService;
use App\Support\OperatingCostEditorVersion;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

final class ListOperatingCosts extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = OperatingCostResource::class;

    #[Locked]
    public ?string $pendingVersion = null;

    #[Locked]
    public ?int $pendingRecord = null;

    #[Locked]
    public ?string $exchangeVersion = null;

    public function rememberVersion(OperatingCostPool $record): void
    {
        $this->pendingRecord = (int) $record->id;
        $this->pendingVersion = OperatingCostEditorVersion::for($record);
    }

    public function removeInvoice(OperatingCostPool $record): void
    {
        abort_unless(OperatingCostResource::canDelete($record), 403);
        abort_unless($this->pendingRecord === (int) $record->id && $this->pendingVersion !== null, 409);
        try {
            app(AdminOperatingCostAuthoringService::class)->delete((int) $record->id, $this->pendingVersion);
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->errors()['editor_version'][0] ?? 'تعذر حذف الفاتورة')->warning()->send();

            return;
        }
        Notification::make()->title('تم الحذف')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make(),
            Action::make('report')->label('تقرير التشغيل')->url(route('admin.operating-costs.report')),
            Action::make('exchangeRate')->label('سعر تحويل OpenRouter')
                ->modalSubmitActionLabel('حفظ سعر التحويل')
                ->authorize(fn (): bool => $this->canUpdateExchangeRate())
                ->schema([TextInput::make('rate')->label('الدولار بالجنيه')->numeric()->required()->minValue(0.0001)->maxValue(10000)->step('0.0001')])
                ->modalDescription('للتقارير الجديدة فقط — لا يغيّر سعر تحويل الفواتير المسجلة')
                ->fillForm(function (): array {
                    $settings = app(AdminOperatingCostReadService::class)->settings();
                    $this->exchangeVersion = OperatingCostEditorVersion::exchangeRate($settings);

                    return ['rate' => $settings->openrouter_usd_to_egp_rate];
                })->action(function (array $data): void {
                    abort_unless($this->canUpdateExchangeRate(), 403);
                    abort_unless($this->exchangeVersion !== null, 409);
                    try {
                        app(AdminOperatingCostAuthoringService::class)->updateExchangeRate((float) $data['rate'], $this->exchangeVersion);
                    } catch (ValidationException $exception) {
                        Notification::make()->title($exception->errors()['editor_version'][0] ?? 'تعذر حفظ سعر التحويل')->warning()->send();
                        throw new Halt;
                    }
                    Notification::make()->title('تم حفظ سعر التحويل')->success()->send();
                }),
        ];
    }

    private function canUpdateExchangeRate(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.operating-costs.exchange-rate', 'POST');
    }

    protected function getHeaderWidgets(): array
    {
        return [OperatingCostSummary::class];
    }

    public function applyTableFilters(): void
    {
        $this->getTableFiltersForm()->getState();
        parent::applyTableFilters();
    }
}
