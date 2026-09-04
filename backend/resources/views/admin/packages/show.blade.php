@extends('admin.layouts.app')

@section('page.title', 'تفاصيل الباقة')

@section('content')
@include('admin.payments.partials.navigation')
<div class="row">
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <strong class="card-title">تفاصيل الباقة</strong>
            </div>
            <div class="card-body">
                <table class="table">
                    <tbody>
                        <tr>
                            <th>الاسم (AR)</th>
                            <td>{{ $package->name_ar }}</td>
                        </tr>
                        <tr>
                            <th>الاسم (EN)</th>
                            <td>{{ $package->name_en }}</td>
                        </tr>
                        <tr>
                            <th>السعر</th>
                            <td>{{ $package->price }}</td>
                        </tr>
                        <tr>
                            <th>العملات (Coins)</th>
                            <td>{{ $package->coins }}</td>
                        </tr>
                        <tr>
                            <th>تاريخ الإنشاء</th>
                            <td>{{ $package->created_at }}</td>
                        </tr>
                    </tbody>
                </table>
                <a href="{{ route('admin.packages.edit', $package->id) }}" class="btn btn-warning mt-3">
                    <i class="fa fa-edit"></i> تعديل
                </a>
                <a href="{{ route('admin.packages.index') }}" class="btn btn-secondary mt-3">
                    <i class="fa fa-arrow-left"></i> رجوع
                </a>
            </div>
        </div>
    </div>
    <div class="col-lg-8 mb-4">
        <div class="card">
            <div class="card-header">
                <strong class="card-title">عمليات الدفع لهذه الباقة</strong>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>العملية</th>
                            <th>المستخدم</th>
                            <th>القناة</th>
                            <th>الحالة</th>
                            <th>الإجمالي</th>
                            <th>الصافي</th>
                            <th>التاريخ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($orders as $order)
                        <tr>
                            <td><a href="{{ route('admin.orders.show', $order) }}">#{{ $order->id }}</a></td>
                            <td>{{ $order->user?->name ?: 'حساب محذوف' }}</td>
                            <td>{{ $paymentMethodLabels[$order->payment_method] ?? $order->payment_method }}</td>
                            <td>{{ $order->payment_operation_label }}</td>
                            <td>{{ number_format((float) ($order->gateway_gross_amount ?? $order->final_amount), 2) }} {{ $order->gateway_currency ?: 'EGP' }}</td>
                            <td>{{ $order->gateway_net_amount === null ? 'بانتظار التسوية' : number_format((float) $order->gateway_net_amount, 2).' '.($order->gateway_currency ?: 'EGP') }}</td>
                            <td>{{ \App\Support\BusinessClock::format($order->created_at, 'Y-m-d H:i') }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="text-center">لا توجد محاولات دفع لهذه الباقة بعد.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
                <div class="mt-3">{{ $orders->links() }}</div>
            </div>
        </div>
    </div>
</div>
@endsection
