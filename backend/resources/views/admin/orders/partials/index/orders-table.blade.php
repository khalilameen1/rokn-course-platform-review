    <!-- Orders Table -->
    <div class="row">
        <div class="col-12">
            <div class="card modern-card">
                <div class="card-header-modern">
                    <h4>
                        <i class="fa fa-list"></i>
                        قائمة الطلبات
                    </h4>
                    <div class="orders-count-badge">
                        <i class="fa fa-file-text"></i>
                        <span>{{ $orders->total() }} طلب</span>
                    </div>
                </div>
                <div class="card-body orders-table-card-body">
                    @if($orders->count() > 0)
                    <div class="table-responsive">
                        <table class="table table-modern">
                            <thead>
                                <tr>
                                    <th class="text-center orders-column--id">#</th>
                                    <th class="text-center">العميل</th>
                                    <th class="text-center">المنتج</th>
                                    <th class="text-center orders-column--amount">الإجمالي والصافي</th>
                                    <th class="text-center orders-column--payment">القناة ودليل المزود</th>
                                    <th class="text-center orders-column--status">الحالة</th>
                                    <th class="text-center orders-column--date">تاريخ الإنشاء</th>
                                    <th class="text-center orders-column--actions">الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($orders as $order)
                                <tr>
                                    <td class="text-center">
                                        <a href="{{ route('admin.orders.show', $order) }}" class="order-id">
                                            #{{ $order->id }}
                                        </a>
                                    </td>
                                    <td class="text-center">
                                        <div class="user-info">
                                            <h6>{{ $order->user?->name ?: 'حساب محذوف' }}</h6>
                                            <small>{{ $order->user?->email ?: '—' }}</small>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <div class="course-info">
                                            @if($order->course)
                                                <h6>{{ Str::limit($order->course->title, 40) }}</h6>
                                                @php
                                                    $planSnapshot = is_array($order->access_plan_snapshot)
                                                        ? $order->access_plan_snapshot
                                                        : [];
                                                @endphp
                                                @if($planSnapshot !== [])
                                                    <small class="text-muted">{{ $planSnapshot['name_ar'] ?? $planSnapshot['code'] ?? 'فئة غير محددة' }}</small>
                                                @endif
                                            @elseif($order->package)
                                                <h6><a href="{{ route('admin.packages.show', $order->package) }}">{{ Str::limit($order->package->name_ar ?: $order->package->name_en, 40) }}</a></h6>
                                                <small class="text-muted">{{ number_format($order->package_coins ?? $order->package->coins) }} عملة ركن</small>
                                            @else
                                                <h6>طلب بدون منتج مرتبط</h6>
                                            @endif
                                            @if($order->courseCode)
                                                <small class="text-info"><i class="fa fa-ticket"></i> كود: {{ $order->courseCode->code }}</small>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        @php
                                            $isCashChannel = in_array($order->payment_method, ['kashier', 'google_play', 'app_store'], true);
                                            $isWalletOrder = in_array($order->payment_method, ['wallet', 'wallet_coins'], true);
                                            $displayAmount = $isWalletOrder
                                                ? (int) ($order->ledger_total_coins ?? 0)
                                                : (float) ($isCashChannel ? ($order->gateway_gross_amount ?? $order->final_amount) : $order->final_amount);
                                            $displayUnit = $isWalletOrder ? 'عملة ركن' : ($isCashChannel ? ($order->gateway_currency ?: 'EGP') : 'جنيه');
                                        @endphp
                                        <div class="amount-display">{{ number_format($displayAmount, $isWalletOrder ? 0 : 2) }}</div>
                                        <small class="text-muted">{{ $displayUnit }}</small>
                                        @if($isCashChannel && ($order->gateway_gross_amount === null || $order->gateway_settlement_status === 'catalog_estimate'))
                                            <br><small class="text-warning">تقدير كتالوج</small>
                                        @endif
                                        @if($isCashChannel)
                                            <br><small class="{{ $order->gateway_net_amount === null ? 'text-warning' : 'text-success' }}">
                                                الصافي: {{ $order->gateway_net_amount === null ? 'بانتظار التسوية' : number_format($order->gateway_net_amount, 2).' '.($order->gateway_currency ?: 'EGP') }}
                                            </small>
                                        @endif
                                        @if($isWalletOrder && !$order->coin_allocation_complete)
                                            <br><small class="text-warning">ربط الدفتر غير مكتمل</small>
                                        @endif
                                        @if($order->discount_amount > 0)
                                            <br><small class="discount-info"><i class="fa fa-tag"></i> خصم: {{ number_format($order->discount_amount, 2) }}</small>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <span class="payment-badge badge-secondary">
                                            <i class="fa fa-money"></i> {{ $paymentMethodLabels[$order->payment_method] ?? $order->payment_method }}
                                        </span>
                                        @if($order->gateway_settlement_status === 'test_purchase')
                                            <br><span class="badge badge-info mt-1">عملية اختبار — خارج الإيراد</span>
                                        @elseif(in_array($order->payment_method, ['kashier', 'google_play', 'app_store'], true) && $order->gateway_net_amount === null)
                                            <br><span class="badge badge-warning mt-1">الصافي بانتظار التسوية</span>
                                        @endif
                                        @if($order->provider_evidence_status)
                                            <br><small class="text-muted admin-code">{{ $order->provider_evidence_status }}</small>
                                        @endif
                                        @if($order->provider_evidence_source)
                                            <br><small class="text-muted">{{ $order->provider_evidence_source }}</small>
                                        @endif
                                        @php($paymentEvidenceUrl = $order->payment_screenshot_url)
                                        @if($paymentEvidenceUrl)
                                            <br>
                                            <img src="{{ $paymentEvidenceUrl }}"
                                                 alt="إيصال الدفع"
                                                 class="payment-screenshot-thumb mt-2"
                                                 onclick="showPaymentScreenshot(@js($paymentEvidenceUrl))"
                                                 title="عرض إيصال الدفع">
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @php($operationTone = $order->payment_operation_tone === 'muted' ? 'secondary' : $order->payment_operation_tone)
                                        <span class="order-badge badge-{{ $operationTone }}">{{ $order->payment_operation_label }}</span>
                                        @if($order->status === \App\Models\Order::STATUS_APPROVED && !$order->isFinanciallyEffective())
                                            <br><small class="text-danger">{{ $order->financialStatusLabel() }}</small>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <div class="order-created-at">
                                            <div class="order-created-at__date">{{ \App\Support\BusinessClock::format($order->created_at, 'Y-m-d') }}</div>
                                            <small class="text-muted">{{ \App\Support\BusinessClock::format($order->created_at, 'H:i') }}</small>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <div class="dropdown action-dropdown">
                                            <button class="btn dropdown-toggle" type="button" data-toggle="dropdown" aria-expanded="false">
                                                <i class="fa fa-bars"></i>
                                            </button>
                                            <div class="dropdown-menu dropdown-menu-right">
                                                <a class="dropdown-item" href="{{ route('admin.orders.show', $order) }}">
                                                    <i class="fa fa-eye"></i> مشاهدة
                                                </a>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="d-flex justify-content-center orders-pagination">
                        {{ $orders->links() }}
                    </div>
                    @else
                    <div class="empty-state">
                        <i class="fa fa-inbox fa-5x"></i>
                        <h5>لا توجد طلبات</h5>
                        <p class="text-muted">لم يتم العثور على أي طلبات تطابق معايير البحث.</p>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
