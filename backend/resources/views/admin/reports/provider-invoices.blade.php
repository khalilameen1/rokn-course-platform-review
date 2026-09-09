<div class="card modern-card mb-4">
    <div class="card-header-modern"><h4 class="mb-0">فواتير التشغيل المسجلة</h4></div>
    <div class="card-body">
        <p class="text-muted">فواتير نهائية حسب تاريخ نهاية فترة الفاتورة دون توزيع تقديري على الأيام أو الطلاب</p>
        @if(!$invoiceReport['has_invoices'])
            <p class="mb-0 text-warning">لم تسجل فواتير لهذه الفترة بعد وهذا لا يعني أن التشغيل بلا تكلفة</p>
        @else
            <strong>{{ number_format($invoiceReport['known_total_egp'], 2) }} جنيه</strong>
            <span class="text-muted">إجمالي الفواتير التي اكتمل تسجيل قيمتها بالجنيه وليس إجمالي كل الخدمات</span>
            @if($invoiceReport['missing_fx'] > 0)
                <p class="text-warning">{{ $invoiceReport['missing_fx'] }} فاتورة تنتظر سعر التحويل المسجل وقت الفاتورة</p>
            @endif
            <div class="table-responsive mt-3"><table class="table mb-0">
                <thead><tr><th>الخدمة</th><th>الفواتير</th><th>القيمة بالجنيه</th></tr></thead>
                <tbody>@foreach($invoiceReport['services'] as $service)
                    <tr><td>{{ $service['label'] }}</td><td>{{ $service['invoices'] }}</td><td>{{ $service['actual_egp'] !== null ? number_format($service['actual_egp'], 2) : 'غير مكتملة' }}</td></tr>
                @endforeach</tbody>
            </table></div>
        @endif
        <a class="d-inline-block mt-3" href="{{ route('admin.operating-costs.index') }}">فواتير الخدمات</a>
    </div>
</div>
