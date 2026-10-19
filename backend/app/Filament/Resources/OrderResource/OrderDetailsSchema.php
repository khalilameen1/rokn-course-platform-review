<?php

namespace App\Filament\Resources\OrderResource;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Models\Order;
use App\Support\BusinessClock;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Native presentation of the existing financial reader, never a financial writer. */
final class OrderDetailsSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make('تفاصيل الطلب')->columns(2)->schema([
                TextEntry::make('order_ref')->label('المرجع')->copyable()->extraAttributes(['dir' => 'ltr'])->columnSpanFull(),
                TextEntry::make('state')->label('حالة الدفع')->state(fn (ViewOrder $livewire): string => $livewire->order()->getAttribute('payment_operation_label'))->badge(),
                TextEntry::make('financial')->label('الحالة المالية')->state(fn (Order $record): string => $record->financialStatusLabel()),
                TextEntry::make('student')->label('الطالب')->state(fn (Order $record): string => $record->user?->getFilamentName() ?? 'حساب محذوف')
                    ->url(fn (Order $record): ?string => $record->user && self::allows('admin.users.show') ? route('admin.users.show', $record->user) : null),
                TextEntry::make('user.email')->label('البريد')->placeholder('—')->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('user.phone')->label('الهاتف')->placeholder('—')->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('product')->label('المنتج')->state(fn (Order $record): string => $record->course->title ?? $record->package->name_ar ?? $record->package->name_en ?? 'بدون منتج مرتبط'),
                TextEntry::make('plan')->label('الاشتراك وقت الشراء')->state(fn (Order $record): ?string => $record->access_plan_snapshot['name_ar'] ?? $record->access_plan_snapshot['code'] ?? null)->placeholder('—')
                    ->visible(fn (Order $record): bool => $record->course_id !== null),
                TextEntry::make('plan_price')->label('سعر الاشتراك وقت الشراء')->state(fn (Order $record): ?string => isset($record->access_plan_snapshot['price_coins']) ? number_format((int) $record->access_plan_snapshot['price_coins']).' عملة رُكن' : null)
                    ->visible(fn (Order $record): bool => isset($record->access_plan_snapshot['price_coins'])),
                TextEntry::make('courseCode.code')->label('كود المنحة')->visible(fn (Order $record): bool => $record->course_code_id !== null),
                TextEntry::make('coupon_code')->label('كوبون الخصم')->state(fn (Order $record): string => $record->coupon_code ?: 'كوبون #'.$record->coupon_id)
                    ->visible(fn (Order $record): bool => filled($record->coupon_code) || $record->coupon_id !== null),
                TextEntry::make('created_at')->label('الإنشاء بتوقيت القاهرة')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName()),
                TextEntry::make('approvedBy.name')->label('اعتمد بواسطة')->visible(fn (Order $record): bool => $record->approved_by !== null),
                TextEntry::make('approved_at')->label('تاريخ الاعتماد')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName())->visible(fn (Order $record): bool => $record->approved_at !== null),
                TextEntry::make('notes')->label('الملاحظات')->visible(fn (Order $record): bool => filled($record->notes))->columnSpanFull(),
            ]),
            Section::make('المبلغ والتحصيل')->columns(2)->schema([
                TextEntry::make('payment_channel')->label('قناة الدفع')->state(fn (ViewOrder $livewire): string => $livewire->details()['paymentMethodLabels'][$livewire->order()->payment_method] ?? $livewire->order()->payment_method)->badge(),
                TextEntry::make('test_purchase')->label('نوع العملية')->state(fn (Order $record): string => $record->gateway_settlement_status === 'test_purchase' ? 'اختبار — لا يدخل في الإيراد' : 'عملية فعلية'),
                ...collect(['ledger_total_coins' => 'تكلفة فتح الكورس', 'ledger_paid_coins' => 'من الرصيد المدفوع', 'ledger_reward_coins' => 'من المكافآت'])
                    ->map(fn (string $label, string $key): TextEntry => TextEntry::make($key)->label($label)
                        ->state(fn (ViewOrder $livewire): string => number_format((int) $livewire->order()->getAttribute($key)).' عملة رُكن')
                        ->visible(fn (Order $record): bool => self::isWallet($record)))->values()->all(),
                TextEntry::make('allocation')->label('ربط دفتر العملات')->state(fn (ViewOrder $livewire): string => $livewire->order()->getAttribute('coin_allocation_complete') ? 'مكتمل' : 'غير مكتمل')
                    ->visible(fn (Order $record): bool => self::isWallet($record)),
                ...collect(['amount' => 'المبلغ الأساسي', 'discount_amount' => 'الخصم', 'final_amount' => 'المبلغ النهائي',
                    'gateway_gross_amount' => 'إجمالي المزود', 'gateway_fee_amount' => 'رسوم المزود', 'gateway_net_amount' => 'الصافي المؤكد'])
                    ->map(fn (string $label, string $key): TextEntry => TextEntry::make($key)->label($label)
                        ->state(fn (Order $record): string => $record->getAttribute($key) === null ? 'بانتظار كشف التسوية' : number_format((float) $record->getAttribute($key), 2).' '.($record->gateway_currency ?: 'EGP'))
                        ->visible(fn (Order $record): bool => ! self::isWallet($record)))->values()->all(),
                TextEntry::make('settlement_kind')->label('مصدر الإجمالي')->state(fn (Order $record): string => $record->gateway_gross_amount === null || $record->gateway_settlement_status === 'catalog_estimate' ? 'تقدير الكتالوج' : 'إجمالي المزود المسجل')
                    ->visible(fn (Order $record): bool => ! self::isWallet($record)),
                TextEntry::make('shortfall')->label('تمويل الاشتراك')->state(fn (Order $record): string => 'دفع الجزء الناقص فقط · '.number_format($record->packageCoinAmount()).' عملة رُكن')
                    ->visible(fn (ViewOrder $livewire): bool => ($livewire->order()->courseFundingCheckout?->terms['funding_mode'] ?? null) === 'exact_shortfall'),
                TextEntry::make('gateway_settlement_status')->label('حالة التسوية')->placeholder('غير واردة بعد')
                    ->formatStateUsing(fn (string $state): string => ['settled' => 'تمت التسوية', 'captured' => 'تم التحصيل', 'provider_verified' => 'تحقق المزود', 'catalog_estimate' => 'تقدير الكتالوج', 'test_purchase' => 'عملية اختبار'][$state] ?? $state),
                TextEntry::make('gateway_settled_at')->label('التسوية بتوقيت القاهرة')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName())->placeholder('—'),
                TextEntry::make('recovery')->label('المراجعة المالية')->state(fn (Order $record): string => 'استُرد '.$record->recovered_coins.' والمتبقي '.$record->unrecovered_coins.' عملة')
                    ->visible(fn (Order $record): bool => $record->financial_status === Order::FINANCIAL_REVIEW_REQUIRED)->columnSpanFull(),
            ]),
            Section::make('دليل مزود الدفع')->columns(2)->schema([
                TextEntry::make('provider_status')->label('حالة المزود')->state(fn (ViewOrder $livewire): ?string => $livewire->order()->getAttribute('provider_evidence_status'))->placeholder('لم تصل حالة قابلة للتحقق'),
                TextEntry::make('provider_source')->label('مصدر الدليل')->state(fn (ViewOrder $livewire): ?string => $livewire->order()->getAttribute('provider_evidence_source'))->placeholder('—'),
                TextEntry::make('reference')->label('مرجع المزود')->state(fn (Order $record): ?string => $record->transaction_id ?: $record->storePurchase?->external_transaction_id)->placeholder('—')->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('storePurchase.environment')->label('البيئة')->placeholder('—'),
                TextEntry::make('verification')->hiddenLabel()->state('فتح صفحة الدفع لا يثبت التحصيل — حالة الطلب تأتي من دليل المزود أو طابور المطابقة')
                    ->visible(fn (Order $record): bool => $record->status === Order::STATUS_PENDING && $record->requiresProviderVerification())->columnSpanFull(),
                TextEntry::make('findings')->label('نتائج المطابقة')->state(fn (ViewOrder $livewire): int => $livewire->details()['reconciliationFindingsCount'])
                    ->url(fn (Order $record): ?string => self::allows('admin.payment-reconciliation-findings.index') ? route('admin.payment-reconciliation-findings.index', ['state' => '', 'order_ref' => $record->order_ref]) : null),
            ])->columnSpanFull(),
            Section::make('إيصال الدفع')->visible(fn (Order $record): bool => filled($record->payment_screenshot_url))->schema([
                ImageEntry::make('receipt')->hiddenLabel()->alt('إيصال الدفع')->state(fn (Order $record): ?string => $record->payment_screenshot_url)
                    ->url(fn (Order $record): ?string => $record->payment_screenshot_url)->openUrlInNewTab()->imageHeight(240),
            ])->columnSpanFull(),
        ]);
    }

    private static function isWallet(Order $order): bool
    {
        return in_array($order->payment_method, [Order::PAYMENT_METHOD_WALLET, Order::PAYMENT_METHOD_WALLET_COINS], true);
    }

    private static function allows(string $route): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), $route, 'GET');
    }
}
