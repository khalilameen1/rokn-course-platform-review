<div class="row" id="studentPurchases">
    <div class="col-12">
        <div class="section-card-modern">
            <div class="section-header-modern">
                <h3 class="section-title"><i class="fa fa-shopping-cart"></i> المشتريات</h3>
                <div class="stats-container">
                    <span class="stat-badge stat-badge-light"><i class="fa fa-shopping-bag"></i> {{ $orders->total() }} طلب</span>
                    <span class="stat-badge stat-badge-success"><i class="fa fa-check"></i> معتمد {{ $orderStats['approved'] }}</span>
                    @if($orderStats['pending'] > 0)
                        <span class="stat-badge stat-badge-danger"><i class="fa fa-hourglass"></i> معلق {{ $orderStats['pending'] }}</span>
                    @endif
                </div>
            </div>
            <div class="section-body">
                @if($orders->count() > 0)
                    <div class="table-responsive">
                        <table class="detail-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>المنتج</th>
                                    <th>الفئة</th>
                                    <th>ما دفعه الطالب</th>
                                    <th>الطريقة</th>
                                    <th>الحالة</th>
                                    <th>التاريخ</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($orders as $order)
                                    <tr>
                                        <td><a href="{{ route('admin.orders.show', $order->id) }}" class="user-detail-link"><strong>#{{ $order->id }}</strong></a></td>
                                        <td>
                                            @if($order->course)
                                                <a href="{{ route('admin.orders.show', $order->id) }}" class="user-detail-link"><strong>{{ $order->course->title }}</strong></a>
                                                @if($order->courseCode)
                                                    <br><small class="text-info"><i class="fa fa-ticket"></i> {{ $order->courseCode->code }}</small>
                                                @endif
                                            @elseif($order->package)
                                                <strong>{{ $order->package->name_ar ?: $order->package->name_en }}</strong>
                                                <br><small class="text-muted">{{ number_format((int) ($order->package_coins ?? $order->package->coins)) }} عملة ركن</small>
                                            @else
                                                <span class="text-muted">منتج غير مرتبط</span>
                                            @endif
                                        </td>
                                        <td>
                                            @php($planSnapshot = is_array($order->access_plan_snapshot) ? $order->access_plan_snapshot : [])
                                            {{ $planSnapshot['name_ar'] ?? $planSnapshot['name'] ?? $planSnapshot['code'] ?? '—' }}
                                        </td>
                                        <td>
                                            @if(in_array($order->payment_method, [\App\Models\Order::PAYMENT_METHOD_WALLET, \App\Models\Order::PAYMENT_METHOD_WALLET_COINS], true))
                                                <strong>{{ number_format((int) $order->ledger_paid_coins) }} مدفوعة</strong>
                                                <br><small>{{ number_format((int) $order->ledger_reward_coins) }} مكافآت</small>
                                                @if(!$order->coin_allocation_complete)
                                                    <br><small class="text-warning">بيانات العملات غير مكتملة</small>
                                                @endif
                                            @elseif($order->gateway_settlement_status === 'test_purchase')
                                                <strong class="text-info">شراء اختبار</strong>
                                            @elseif($order->gateway_gross_amount !== null && $order->gateway_settlement_status !== 'catalog_estimate')
                                                <strong>{{ number_format((float) $order->gateway_gross_amount, 2) }} {{ strtoupper((string) ($order->gateway_currency ?: 'EGP')) }}</strong>
                                            @elseif($order->payment_method === \App\Models\Order::PAYMENT_METHOD_COURSE_CODE)
                                                <strong>إتاحة بكود</strong>
                                            @elseif(in_array($order->payment_method, [\App\Models\Order::PAYMENT_METHOD_KASHIER, \App\Models\Order::PAYMENT_METHOD_GOOGLE_PLAY, \App\Models\Order::PAYMENT_METHOD_APP_STORE], true))
                                                <strong class="text-warning">المبلغ غير مؤكد</strong>
                                            @else
                                                <strong>{{ number_format((float) $order->final_amount, 2) }} {{ strtoupper((string) ($order->gateway_currency ?: 'EGP')) }}</strong>
                                            @endif
                                            @if((float) $order->discount_amount > 0)
                                                <br><small class="text-success"><i class="fa fa-tag"></i> خصم {{ number_format((float) $order->discount_amount, 2) }}</small>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="stat-badge stat-badge-light">{{ $paymentMethodLabels[$order->payment_method] ?? optional($order->paymentMethod)->name ?? $order->payment_method }}</span>
                                        </td>
                                        <td>
                                            @switch($order->status)
                                                @case(\App\Models\Order::STATUS_APPROVED)
                                                    <span class="stat-badge {{ $order->isFinanciallyEffective() ? 'stat-badge-success' : 'stat-badge-danger' }}">{{ $order->financialStatusLabel() }}</span>
                                                    @break
                                                @case(\App\Models\Order::STATUS_PENDING)
                                                    <span class="stat-badge stat-badge-danger">معلق</span>
                                                    @break
                                                @case(\App\Models\Order::STATUS_REJECTED)
                                                    <span class="stat-badge stat-badge-danger">مرفوض</span>
                                                    @break
                                                @default
                                                    <span class="stat-badge stat-badge-light">ملغي</span>
                                            @endswitch
                                        </td>
                                        <td>{{ \App\Support\BusinessClock::format($order->created_at) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($orders->hasPages())
                        <div class="d-flex justify-content-center mt-3">{{ $orders->links() }}</div>
                    @endif
                @else
                    <div class="empty-state-modern"><i class="fa fa-shopping-cart"></i><h4>لا توجد مشتريات</h4></div>
                @endif
            </div>
        </div>
    </div>
</div>
