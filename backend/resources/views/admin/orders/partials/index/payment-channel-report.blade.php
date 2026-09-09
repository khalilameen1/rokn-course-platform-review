<div class="row mb-4">
    <div class="col-12">
        <div class="card modern-card">
            <div class="card-header-modern d-flex flex-wrap align-items-center justify-content-between">
                <h4 class="mb-0"><i class="fa fa-credit-card"></i> تحصيل باقات العملات حسب القناة</h4>
                <small class="text-muted">عمليات الاختبار لا تدخل في أي مبلغ مالي</small>
            </div>
            <div class="table-responsive">
                <table class="table table-modern mb-0">
                    <thead>
                        <tr>
                            <th>القناة</th>
                            <th>عمليات حقيقية</th>
                            <th>عملات مُصدرة</th>
                            <th>الإجمالي المؤكد</th>
                            <th>رسوم مؤكدة</th>
                            <th>صافي مؤكد</th>
                            <th>اختبار</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($paymentChannelReport['rows'] as $channel)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.orders.index', ['payment_method' => $channel['method']]) }}">
                                        <strong>{{ $channel['label'] }}</strong>
                                    </a>
                                    <br><small class="text-muted">{{ $channel['currency'] === 'PENDING' ? 'العملة بانتظار كشف المزود' : $channel['currency'] }}</small>
                                </td>
                                <td>{{ number_format($channel['live_count']) }}</td>
                                <td>{{ number_format($channel['live_coins']) }}</td>
                                <td>
                                    {{ $channel['live_count'] > 0 && $channel['confirmed_gross_count'] === 0 ? 'بانتظار تأكيد التحصيل' : number_format($channel['confirmed_gross_amount'], 2) }}
                                    @if($channel['confirmed_gross_count'] > 0 && $channel['confirmed_gross_count'] < $channel['live_count'])
                                        <br><small class="text-warning">جزئي · {{ $channel['confirmed_gross_count'] }} من {{ $channel['live_count'] }} عملية</small>
                                    @endif
                                    @if($channel['catalog_estimated_gross_count'] > 0)
                                        <br><small class="text-warning">+ {{ number_format($channel['catalog_estimated_gross_amount'], 2) }} تقدير كتالوج خارج الإجمالي · {{ $channel['catalog_estimated_gross_count'] }} عملية</small>
                                    @endif
                                </td>
                                <td>
                                    {{ $channel['live_count'] > 0 && $channel['confirmed_fee_count'] === 0 ? 'بانتظار التسوية' : number_format($channel['confirmed_fee_amount'], 2) }}
                                    @if($channel['confirmed_fee_count'] > 0 && $channel['confirmed_fee_count'] < $channel['live_count'])
                                        <br><small class="text-warning">جزئي · {{ $channel['confirmed_fee_count'] }} من {{ $channel['live_count'] }} عملية</small>
                                    @endif
                                </td>
                                <td>
                                    {{ $channel['live_count'] > 0 && $channel['confirmed_net_count'] === 0 ? 'بانتظار التسوية' : number_format($channel['confirmed_net_amount'], 2) }}
                                    @if($channel['confirmed_net_count'] > 0 && $channel['confirmed_net_count'] < $channel['live_count'])
                                        <br><small class="text-warning">جزئي · {{ $channel['confirmed_net_count'] }} من {{ $channel['live_count'] }} عملية</small>
                                    @endif
                                </td>
                                <td>
                                    {{ number_format($channel['test_count']) }}
                                    @if($channel['test_coins'] > 0)
                                        <br><small class="text-muted">{{ number_format($channel['test_coins']) }} عملة</small>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        @php
                            $egp = $paymentChannelReport['egp'];
                            $egpNetCount = (int) $paymentChannelReport['rows']->where('currency', 'EGP')->sum('confirmed_net_count');
                        @endphp
                        <tr>
                            <th>الإجمالي بالجنيه</th>
                            <th>{{ number_format($paymentChannelReport['egp']['live_count']) }}</th>
                            <th>{{ number_format($paymentChannelReport['egp']['live_coins']) }}</th>
                            <th>
                                {{ $egp['live_count'] > 0 && $egp['confirmed_gross_count'] === 0 ? 'بانتظار تأكيد التحصيل' : number_format($egp['confirmed_gross_amount'], 2) }}
                                @if($egp['confirmed_gross_count'] > 0 && $egp['confirmed_gross_count'] < $egp['live_count'])
                                    <br><small class="text-warning">جزئي · {{ $egp['confirmed_gross_count'] }} من {{ $egp['live_count'] }} عملية</small>
                                @endif
                                @if($paymentChannelReport['egp']['catalog_estimated_gross_count'] > 0)<br><small>+ {{ number_format($paymentChannelReport['egp']['catalog_estimated_gross_amount'], 2) }} تقديري خارج الإجمالي</small>@endif
                            </th>
                            <th>
                                {{ $egp['live_count'] > 0 && $egp['confirmed_fee_count'] === 0 ? 'بانتظار التسوية' : number_format($egp['confirmed_fee_amount'], 2) }}
                                @if($egp['confirmed_fee_count'] > 0 && $egp['confirmed_fee_count'] < $egp['live_count'])
                                    <br><small class="text-warning">جزئي · {{ $egp['confirmed_fee_count'] }} من {{ $egp['live_count'] }} عملية</small>
                                @endif
                            </th>
                            <th>
                                {{ $egp['live_count'] > 0 && $egpNetCount === 0 ? 'بانتظار التسوية' : number_format($egp['confirmed_net_amount'], 2) }}
                                @if($egpNetCount > 0 && $egpNetCount < $egp['live_count'])
                                    <br><small class="text-warning">جزئي · {{ $egpNetCount }} من {{ $egp['live_count'] }} عملية</small>
                                @endif
                            </th>
                            <th>{{ number_format($paymentChannelReport['egp']['test_count']) }}</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            @if($paymentChannelReport['has_other_currencies'])
                <div class="card-footer text-muted">
                    لا تُجمع العملات المختلفة أو العمليات التي لم يصل كشف عملتها مع الجنيه.
                </div>
            @endif
        </div>
    </div>
</div>
