@extends('layouts.landing')

@section('title', 'حذف حساب ركن')
@section('meta_description', 'صفحة طلب حذف حساب ركن والبيانات المرتبطة به')

@section('content')
    <style>
        .deletion-page{max-width:860px;margin:0 auto;padding:72px 22px 96px;color:#f5f7fb;direction:rtl;text-align:right}
        .deletion-shell{background:linear-gradient(155deg,#111827 0%,#0b1020 100%);border:1px solid rgba(132,151,190,.22);border-radius:28px;padding:clamp(24px,5vw,48px);box-shadow:0 28px 80px rgba(0,0,0,.28)}
        .deletion-kicker{display:inline-flex;padding:7px 12px;border-radius:999px;background:rgba(52,113,235,.14);color:#88afff;font-weight:800;font-size:14px}
        .deletion-page h1{margin:18px 0 12px;font-size:clamp(32px,6vw,50px);line-height:1.2;color:#fff}
        .deletion-lead{color:#b8c1d4;font-size:18px;line-height:1.9;margin:0 0 30px}
        .deletion-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin:24px 0 34px}
        .deletion-card{border:1px solid rgba(132,151,190,.2);background:rgba(255,255,255,.035);border-radius:18px;padding:20px}
        .deletion-card h2{font-size:18px;margin:0 0 10px;color:#fff}
        .deletion-card p{font-size:14px;line-height:1.85;color:#aeb8cc;margin:0}
        .deletion-success{border:1px solid rgba(52,211,153,.35);background:rgba(16,185,129,.09);border-radius:20px;padding:24px;margin-top:28px}
        .deletion-success h2{color:#d8fff1;margin:0 0 8px}
        .deletion-reference{direction:ltr;display:inline-block;color:#7de4bd;font-weight:800;letter-spacing:.04em}
        .deletion-form{display:grid;gap:18px;margin-top:30px}
        .deletion-field label{display:block;color:#f2f5fb;font-weight:700;margin-bottom:8px}
        .deletion-field input,.deletion-field textarea{box-sizing:border-box;width:100%;border:1px solid rgba(132,151,190,.3);border-radius:14px;background:#090e1a;color:#fff;font:inherit;padding:14px 16px;outline:none;text-align:right}
        .deletion-field input:focus,.deletion-field textarea:focus{border-color:#4e82ed;box-shadow:0 0 0 4px rgba(78,130,237,.12)}
        .deletion-field textarea{min-height:112px;resize:vertical}
        .deletion-note{color:#8f9bb1;font-size:13px;line-height:1.7;margin-top:6px}
        .deletion-confirm{display:flex;align-items:flex-start;gap:10px;color:#c8d0df;line-height:1.65}
        .deletion-confirm input{width:20px;height:20px;margin-top:3px;accent-color:#3471eb;flex:0 0 auto}
        .deletion-button{border:0;border-radius:15px;background:linear-gradient(180deg,#397bef,#2863d1);color:#fff;font:inherit;font-weight:800;font-size:17px;padding:15px 22px;cursor:pointer;box-shadow:0 14px 32px rgba(39,99,210,.25)}
        .deletion-errors{border:1px solid rgba(248,113,113,.35);background:rgba(239,68,68,.08);border-radius:16px;padding:16px 20px;color:#ffd1d1}
        .deletion-errors ul{margin:0;padding-right:20px}
        .deletion-hp{position:absolute!important;right:-10000px!important;width:1px!important;height:1px!important;overflow:hidden!important}
        @media(max-width:680px){.deletion-page{padding:38px 14px 72px}.deletion-shell{border-radius:22px}.deletion-grid{grid-template-columns:1fr}}
    </style>

    <div class="deletion-page">
        <section class="deletion-shell">
            <span class="deletion-kicker">خصوصيتك تحت سيطرتك</span>
            <h1>طلب حذف حسابك</h1>
            <p class="deletion-lead">
                يمكنك إرسال الطلب حتى لو لم تعد قادرًا على تسجيل الدخول. سنتحقق من ملكية الحساب قبل التنفيذ،
                ولن نؤكد في هذه الصفحة وجود حساب بالبريد الذي أدخلته حفاظًا على الخصوصية.
            </p>

            <div class="deletion-grid">
                <article class="deletion-card">
                    <h2>ما الذي يُحذف؟</h2>
                    <p>بيانات ملفك وروابط تسجيل الدخول ورموز الجلسات وأجهزة الإشعارات وسجل المشاهدة والمرفقات الشخصية القابلة للحذف. ويختفي ملفك وأعمالك وشهاداتك من العرض العام.</p>
                </article>
                <article class="deletion-card">
                    <h2>ما الذي قد يبقى؟</h2>
                    <p>سجل محدود للمدفوعات والفواتير وحركات الرصيد عند الحاجة للتسويات أو منع الاحتيال أو الالتزام القانوني. يُفصل هذا السجل عن ملفك العام ولا يُستخدم للتسويق.</p>
                </article>
            </div>

            @if(session('deletion_request_submitted'))
                <div class="deletion-success" role="status">
                    <h2>استلمنا طلبك</h2>
                    <p>احتفظ برقم المتابعة <span class="deletion-reference">{{ session('deletion_reference') }}</span>. قد يتواصل معك الدعم للتأكد من ملكية الحساب قبل الحذف.</p>
                </div>
            @else
                @if($errors->any())
                    <div class="deletion-errors" role="alert">
                        <ul>
                            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                <form class="deletion-form" method="POST" action="{{ route('account-deletion.store') }}" novalidate>
                    @csrf
                    <div class="deletion-hp" aria-hidden="true">
                        <label for="website">اترك هذا الحقل فارغًا</label>
                        <input id="website" name="website" type="text" value="" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="deletion-field">
                        <label for="name">الاسم الموجود على الحساب</label>
                        <input id="name" name="name" type="text" maxlength="120" value="{{ old('name') }}" autocomplete="name" required>
                    </div>
                    <div class="deletion-field">
                        <label for="email">البريد المرتبط بالحساب</label>
                        <input id="email" name="email" type="email" maxlength="255" value="{{ old('email') }}" autocomplete="email" inputmode="email" dir="ltr" required>
                    </div>
                    <div class="deletion-field">
                        <label for="phone">رقم الهاتف إن كان مضافًا <span class="deletion-note">(اختياري)</span></label>
                        <input id="phone" name="phone" type="tel" maxlength="40" value="{{ old('phone') }}" autocomplete="tel" inputmode="tel">
                    </div>
                    <div class="deletion-field">
                        <label for="reason">ملاحظة للدعم <span class="deletion-note">(اختياري)</span></label>
                        <textarea id="reason" name="reason" maxlength="1000">{{ old('reason') }}</textarea>
                    </div>
                    <label class="deletion-confirm">
                        <input name="confirm" type="checkbox" value="1" required @checked(old('confirm'))>
                        <span>أفهم أن حذف الحساب نهائي، وأن سجلًا ماليًا محدودًا قد يبقى للمدة التي يفرضها القانون أو تتطلبها التسويات.</span>
                    </label>
                    <button class="deletion-button" type="submit">إرسال طلب الحذف</button>
                </form>
            @endif
        </section>
    </div>
@endsection
