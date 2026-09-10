@extends('web-wallet.layout')
@section('title', 'حالة الشحن')
@php
    $paid = $order->isFinanciallyEffective();
    $pending = $order->status === \App\Models\Order::STATUS_PENDING;
    $review = $order->financial_status === \App\Models\Order::FINANCIAL_REVIEW_REQUIRED;
    $providerPending = session('wallet_provider_pending') === $order->order_ref;
@endphp
@section('content')
    <section class="panel receipt" data-payment-receipt data-status-url="{{ route('web-wallet.status', $order->order_ref) }}" data-reconcile-url="{{ route('web-wallet.reconcile', $order->order_ref) }}" data-csrf="{{ csrf_token() }}" data-rendered-status="{{ $order->status }}" data-rendered-financial="{{ $order->financial_status }}" data-login-url="{{ route('web-wallet.index') }}" data-poll="{{ $pending && !$review ? 'true' : 'false' }}">
        <h1>{{ $paid ? 'وصل رصيدك' : ($review ? 'نراجع عملية الدفع' : ($pending ? 'بانتظار تأكيد الدفع' : 'لم يكتمل الشحن')) }}</h1>
        @if($paid)
            <p class="receipt-coins">{{ number_format($order->package_coins) }} <span>عملة</span></p>
            <p>رصيدك متاح الآن في التطبيق</p>
        @elseif($review)
            <p>لا تحتاج إلى الدفع مرة أخرى</p>
        @elseif($pending)
            <p>ستُضاف العملات بعد وصول تأكيد كاشير</p>
            <p class="muted">{{ $providerPending ? 'الدفع قيد التأكيد لا تحتاج إلى الدفع مرة أخرى' : 'إذا أغلقت صفحة الدفع يمكنك استكمالها' }}</p>
        @else
            <p>يمكنك اختيار الباقة والمحاولة من جديد</p>
        @endif
        <dl><div><dt>قيمة العملية</dt><dd>{{ number_format($order->final_amount, 2) }} ج م</dd></div></dl>
        <p class="muted" data-payment-notice role="status"></p>
        <form action="{{ route('web-wallet.reconcile', $order->order_ref) }}" method="post">@csrf<button class="button secondary">تحديث حالة الدفع</button></form>
        @if($pending && !$review)
            <div class="receipt-actions" data-unconfirmed-actions @if($providerPending) hidden @endif>
                <form action="{{ route('web-wallet.resume', $order->order_ref) }}" method="post" data-checkout-form>@csrf<button class="button primary">استكمال الدفع</button></form>
                <form action="{{ route('web-wallet.abandon', $order->order_ref) }}" method="post">@csrf<button class="text-button">إلغاء المحاولة واختيار باقة أخرى</button></form>
            </div>
        @endif
        @if($paid)<a class="button primary" href="rokn://wallet">فتح ركن</a>@endif
        <a class="button secondary" href="{{ route('web-wallet.index') }}">العودة إلى الرصيد</a>
    </section>
@endsection
