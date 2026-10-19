{{-- Compatibility report for the remaining legacy overview/analytics only. --}}
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
                                @foreach(['gross', 'fee', 'net'] as $kind)
                                    <td>
                                        {{ \App\Support\PaymentReportPresentation::amount($channel, $kind) }}
                                        @if($detail = \App\Support\PaymentReportPresentation::detail($channel, $kind))
                                            <br><small class="text-warning">{{ $detail }}</small>
                                        @endif
                                    </td>
                                @endforeach
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
                            $egp = \App\Support\PaymentReportPresentation::egp($paymentChannelReport);
                        @endphp
                        <tr>
                            <th>الإجمالي بالجنيه</th>
                            <th>{{ number_format($paymentChannelReport['egp']['live_count']) }}</th>
                            <th>{{ number_format($paymentChannelReport['egp']['live_coins']) }}</th>
                            @foreach(['gross', 'fee', 'net'] as $kind)
                                <th>
                                    {{ \App\Support\PaymentReportPresentation::amount($egp, $kind) }}
                                    @if($detail = \App\Support\PaymentReportPresentation::detail($egp, $kind))
                                        <br><small class="text-warning">{{ $detail }}</small>
                                    @endif
                                </th>
                            @endforeach
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
