@extends('web-wallet.layout')

@section('content')
    <h1>شحن رصيدك</h1>
    <p class="intro">نفس حسابك ونفس رصيدك في ركن</p>
    @if(!$student)
        <section class="panel login-panel" aria-labelledby="login-title">
            <h2 id="login-title">سجّل بحسابك في التطبيق</h2>
            <p class="muted">اختر نفس طريقة الدخول ليصلك الرصيد في حسابك</p>
            <div class="login-options">
                @forelse($providers as $provider => $label)
                    <a class="button secondary" href="{{ route('web-wallet.auth.start', $provider) }}">المتابعة مع <bdi>{{ $label }}</bdi></a>
                @empty
                    <p role="status">تسجيل الدخول غير متاح الآن حاول لاحقًا</p>
                @endforelse
            </div>
        </section>
    @endif

    @if($pending)
        <a class="notice pending-link" href="{{ route('web-wallet.receipt', $pending->order_ref) }}">لديك محاولة دفع سابقة <span>متابعة حالتها</span></a>
    @endif

    @if($packages->isNotEmpty())
        <h2 class="section-heading">اختر الباقة</h2>
        <nav class="package-rail" aria-label="باقات العملات">
            @foreach($packages as $package)
                <a class="package {{ $selected->id === $package->id ? 'selected' : '' }}"
                   href="{{ route('web-wallet.index', ['package' => $package->id]) }}"
                   @if($selected->id === $package->id) aria-current="true" @endif>
                    <img src="{{ asset('images/rokn-coin-minted.png') }}" class="coin" alt="" width="42" height="42">
                    <strong>{{ number_format($package->coins) }} <span>عملة</span></strong>
                    <span class="package-price">{{ number_format($pricing->directPrice($package, $discount), 2) }} ج م</span>
                </a>
            @endforeach
        </nav>
        <section class="panel summary" aria-labelledby="summary-title">
            <h2 id="summary-title">ملخص الشحن</h2>
            <dl>
                <div><dt>الرصيد الذي سيصلك</dt><dd>{{ number_format($quote['expected_coins']) }} عملة</dd></div>
                @if($discount > 0)
                    <div><dt>سعر الباقة</dt><dd>{{ number_format($selected->price, 2) }} ج م</dd></div>
                    <div class="saving"><dt>خصم الشحن المباشر {{ $discount }}٪</dt><dd>− {{ number_format((float) $selected->price - $quote['expected_amount'], 2) }} ج م</dd></div>
                @endif
                <div class="total"><dt>الإجمالي</dt><dd>{{ number_format($quote['expected_amount'], 2) }} ج م</dd></div>
            </dl>
        </section>
        @if($student)
            <section class="panel payment-method"><strong>الدفع عبر كاشير</strong><p class="muted">اختر وسيلة الدفع في الخطوة التالية</p></section>
            <form class="pay-bar" action="{{ route('web-wallet.pay') }}" method="post" data-checkout-form>
                @csrf
                <input type="hidden" name="expected_account" value="{{ $student->id }}">
                @foreach($quote as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                <input type="hidden" name="idempotency_key" value="{{ $intent['key'] }}">
                <button type="submit" class="button primary" @disabled(!$canCheckout)>
                    {{ $canCheckout ? 'ادفع الآن' : 'الدفع غير متاح الآن' }}
                    @if($canCheckout)<bdi>{{ number_format($quote['expected_amount'], 2) }} ج م</bdi>@endif
                </button>
            </form>
        @endif
    @else
        <section class="panel"><p>باقات الشحن غير متاحة الآن حاول لاحقًا</p></section>
    @endif
@endsection
