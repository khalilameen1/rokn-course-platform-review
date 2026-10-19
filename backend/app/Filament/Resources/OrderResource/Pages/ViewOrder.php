<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use App\Services\AdminOrderSettlementService;
use App\Services\AdminPaymentOperationsReadService;
use App\Services\OrderLifecycleService;
use App\Services\SupportCaseCompensationService;
use App\Support\BusinessClock;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

/** @extends ViewRecord<Order> */
final class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    private ?array $details = null;

    private ?int $remainingCompensation = null;

    public function getTitle(): string
    {
        return 'الطلب #'.$this->getRecord()->id;
    }

    public function details(): array
    {
        return $this->details ??= app(AdminPaymentOperationsReadService::class)->show($this->getRecord());
    }

    public function order(): Order
    {
        return $this->details()['order'];
    }

    public function compensableCoins(): int
    {
        return $this->remainingCompensation ??= app(SupportCaseCompensationService::class)->remainingCompensableCoins($this->order());
    }

    public function canRecordSettlement(): bool
    {
        $order = $this->order();

        return $order->isFinanciallyEffective() && $order->package_id !== null
            && in_array($order->payment_method, [Order::PAYMENT_METHOD_KASHIER, Order::PAYMENT_METHOD_GOOGLE_PLAY, Order::PAYMENT_METHOD_APP_STORE], true)
            && $order->gateway_settlement_status !== 'test_purchase' && $order->gateway_net_amount === null;
    }

    public function canCompensate(): bool
    {
        $order = $this->order();

        return $order->status === Order::STATUS_APPROVED && $order->financial_status === Order::FINANCIAL_SETTLED
            && $order->course_id !== null && $order->payment_method === Order::PAYMENT_METHOD_WALLET_COINS
            && $order->wallet_transaction_id !== null && $order->getAttribute('coin_allocation_complete')
            && $this->compensableCoins() > 0;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('settlement')->label('توثيق التسوية')->authorize(fn (): bool => OrderResource::allows('record-settlement'))
                ->visible(fn (): bool => $this->canRecordSettlement())->requiresConfirmation()
                ->modalDescription('طابق الأرقام مع كشف المزود — لا يمكن تعديلها بعد الحفظ')->modalSubmitActionLabel('توثيق التسوية')
                ->fillForm(fn (): array => ['gross_amount' => $this->order()->gateway_gross_amount ?? $this->order()->final_amount,
                    'currency' => $this->order()->gateway_currency ?: 'EGP', 'settled_at' => BusinessClock::now()->format('Y-m-d H:i:s')])
                ->schema([
                    TextInput::make('gross_amount')->label('الإجمالي')->required()->numeric()->minValue(0.01)->maxValue(9999999999.99),
                    TextInput::make('currency')->label('العملة')->required()->length(3)->regex('/^[A-Za-z]{3}$/'),
                    TextInput::make('fee_amount')->label('كل الرسوم والاستقطاعات')->required()->numeric()->minValue(0)->maxValue(9999999999.99),
                    TextInput::make('net_amount')->label('الصافي')->required()->numeric()->minValue(0)->maxValue(9999999999.99),
                    DateTimePicker::make('settled_at')->label('تاريخ التسوية بتوقيت القاهرة')->native()->seconds(false)->required(),
                    TextInput::make('provider_reference')->label('مرجع كشف المزود')->required()->minLength(3)->maxLength(191),
                ])->action(fn (Action $action, array $data) => $this->perform($action, 'record-settlement', fn () => app(AdminOrderSettlementService::class)->record($this->getRecord(), $data, (int) auth('web')->id()))),
            Action::make('compensate')->label('تعويض الطالب')->color('warning')->authorize(fn (): bool => OrderResource::allows('compensate-course'))
                ->visible(fn (): bool => $this->canCompensate())->requiresConfirmation()
                ->modalDescription('تعود العملات إلى مكوناتها الأصلية دون إلغاء وصول الطالب إلى الكورس')->modalSubmitActionLabel('تأكيد التعويض')
                ->schema([
                    TextInput::make('amount')->label('عدد العملات')->required()->integer()->minValue(1)->maxValue(fn (): int => min(100000000, $this->compensableCoins()))
                        ->helperText(fn (): string => 'المتاح للتعويض '.$this->compensableCoins().' عملة'),
                    Textarea::make('note')->label('سبب التعويض ورقم المرجع')->required()->minLength(8)->maxLength(1000)->rows(3),
                ])->action(fn (Action $action, array $data) => $this->perform($action, 'compensate-course', fn () => app(OrderLifecycleService::class)->compensateCourseOrder(
                    $this->getRecord(), (int) $data['amount'], trim($data['note']),
                    sprintf('admin-course-compensation:%d:%s', $this->getRecord()->id, hash('sha256', $data['amount'].'|'.trim($data['note']))), (int) auth('web')->id()))),
            Action::make('resolve')->label('اعتماد قرار المراجعة')->color('warning')->authorize(fn (): bool => OrderResource::allows('resolve-financial-review'))
                ->visible(fn (): bool => $this->order()->financial_status === Order::FINANCIAL_REVIEW_REQUIRED && $this->order()->package_id !== null)
                ->requiresConfirmation()->modalDescription('راجع مستندات القرار — يؤثر القرار في رصيد الطالب واستحقاقاته')->modalSubmitActionLabel('اعتماد القرار')
                ->schema([
                    Select::make('resolution')->label('القرار')->required()->options(['repaid' => 'تم السداد أو قبول الاعتراض', 'waived' => 'إعفاء موثق']),
                    Textarea::make('note')->label('سبب القرار ورقم المرجع')->required()->minLength(5)->maxLength(1000)->rows(3),
                ])->action(fn (Action $action, array $data) => $this->perform($action, 'resolve-financial-review', fn () => app(OrderLifecycleService::class)->resolveFinancialReview(
                    $this->getRecord(), $data['resolution'], sprintf('admin-resolution:%d:%s:%s', $this->getRecord()->id, $data['resolution'], hash('sha256', trim($data['note']))),
                    (int) auth('web')->id(), trim($data['note'])))),
        ];
    }

    private function perform(Action $action, string $route, Closure $operation): void
    {
        abort_unless(OrderResource::allows($route), 403);
        try {
            $operation();
        } catch (ValidationException $exception) {
            $path = $this->getMountedActionSchema(mountedAction: $action)?->getStatePath();
            if ($path === null) {
                throw $exception;
            }
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors[$path.'.'.$key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        } catch (\DomainException|\InvalidArgumentException|\UnexpectedValueException) {
            Notification::make()->title('تعذر حفظ الإجراء — راجع البيانات وحالة الطلب ثم أعد المحاولة')->warning()->send();
            throw new Halt;
        }
        $this->getRecord()->refresh();
        $this->details = null;
        $this->remainingCompensation = null;
        Notification::make()->title('تم حفظ الإجراء')->success()->send();
    }
}
