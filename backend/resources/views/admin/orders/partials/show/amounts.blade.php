<div class="amount-section">
    <h6 class="amount-section__heading"><i class="fa fa-money"></i> تفاصيل المبلغ</h6>
    @if(in_array($order->payment_method, ['wallet', 'wallet_coins'], true))
        <div class="amount-row">
            <span class="amount-label">إجمالي تكلفة فتح الكورس:</span>
            <span class="amount-value">{{ number_format($order->ledger_total_coins ?? 0) }} عملة ركن</span>
        </div>
        <div class="amount-row">
            <span class="amount-label">من الرصيد المدفوع:</span>
            <span class="amount-value">{{ number_format($order->ledger_paid_coins ?? 0) }} عملة</span>
        </div>
        <div class="amount-row total">
            <span class="amount-label">من المكافآت:</span>
            <span class="amount-value">{{ number_format($order->ledger_reward_coins ?? 0) }} عملة</span>
        </div>
        @if(!$order->coin_allocation_complete)
            <div class="text-warning mt-2"><small>ربط العملية بدفتر العملات غير مكتمل</small></div>
        @endif
    @else
        <div class="amount-row">
            <span class="amount-label">المبلغ الأساسي:</span>
            <span class="amount-value">{{ number_format($order->amount, 2) }} {{ $order->gateway_currency ?: 'EGP' }}</span>
        </div>
        @if($order->discount_amount > 0)
            <div class="amount-row">
                <span class="amount-label">مبلغ الخصم:</span>
                <span class="amount-value text-success">-{{ number_format($order->discount_amount, 2) }} {{ $order->gateway_currency ?: 'EGP' }}</span>
            </div>
        @endif
        <div class="amount-row total">
            <span class="amount-label">المبلغ النهائي:</span>
            <span class="amount-value">{{ number_format($order->final_amount, 2) }} {{ $order->gateway_currency ?: 'EGP' }}</span>
        </div>
        @if(in_array($order->payment_method, ['kashier', 'google_play', 'app_store'], true))
            <div class="amount-row">
                <span class="amount-label">{{ $order->gateway_gross_amount === null || $order->gateway_settlement_status === 'catalog_estimate' ? 'تقدير الكتالوج:' : 'إجمالي المزود المؤكد:' }}</span>
                <span class="amount-value">{{ number_format($order->gateway_gross_amount ?? $order->final_amount, 2) }} {{ $order->gateway_currency ?: 'EGP' }}</span>
            </div>
            <div class="amount-row">
                <span class="amount-label">رسوم المزود المؤكدة:</span>
                <span class="amount-value">{{ $order->gateway_fee_amount === null ? 'بانتظار كشف التسوية' : number_format($order->gateway_fee_amount, 2).' '.($order->gateway_currency ?: 'EGP') }}</span>
            </div>
            <div class="amount-row total">
                <span class="amount-label">الصافي المؤكد:</span>
                <span class="amount-value">{{ $order->gateway_net_amount === null ? 'بانتظار كشف التسوية' : number_format($order->gateway_net_amount, 2).' '.($order->gateway_currency ?: 'EGP') }}</span>
            </div>
            <div class="mt-2 text-muted"><small>حالة التسوية: {{ $order->gateway_settlement_status ?: 'غير واردة بعد' }}</small></div>
        @endif
    @endif
</div>
