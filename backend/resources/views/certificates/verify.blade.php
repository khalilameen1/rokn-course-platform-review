<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="theme-color" content="#07111f">
    <title>التحقق من الشهادة — ركن</title>
    <style>
        :root{--navy:#07111f;--blue:#245fd4;--paper:#f5f7fa;--ink:#09172c;--muted:#687487;--line:#dfe5ed;--valid:#157347;--revoked:#9f2638}
        *{box-sizing:border-box}
        html{background:var(--paper)}
        body{margin:0;color:var(--ink);background:var(--paper);font-family:"Cairo","Segoe UI",Tahoma,sans-serif;line-height:1.65}
        .top{height:10px;background:var(--blue)}
        main{width:min(720px,calc(100% - 32px));margin:clamp(32px,8vh,88px) auto;padding-bottom:max(40px,env(safe-area-inset-bottom))}
        .brand{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px}.brand strong{font-size:24px}.brand span{color:var(--muted);font-size:14px}
        .card{background:#fff;border:1px solid var(--line);border-radius:24px;padding:clamp(24px,5vw,44px);box-shadow:0 18px 50px rgba(9,23,44,.07)}
        .head{display:flex;align-items:start;justify-content:space-between;gap:18px;padding-bottom:24px;border-bottom:1px solid var(--line)}
        h1{font-size:clamp(25px,5vw,36px);line-height:1.25;margin:0}.status{white-space:nowrap;border-radius:999px;padding:7px 12px;font-size:13px;font-weight:700;background:#edf8f2;color:var(--valid)}.status.revoked{background:#fff0f2;color:var(--revoked)}
        dl{margin:0}.row{display:grid;grid-template-columns:130px minmax(0,1fr);gap:18px;padding:18px 0;border-bottom:1px solid var(--line)}.row:last-child{border-bottom:0}dt{color:var(--muted);font-size:13px}dd{margin:0;font-weight:650;overflow-wrap:anywhere}.course{font-size:19px}.number{direction:ltr;text-align:right;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:12px}
        .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:26px}.action{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 18px;border-radius:12px;text-decoration:none;font-weight:700}.primary{background:var(--navy);color:#fff}.secondary{border:1px solid var(--line);color:var(--ink)}
        @media(max-width:560px){main{width:min(100% - 20px,720px);margin-top:24px}.brand{padding:0 6px}.card{border-radius:20px}.head{display:block}.status{display:inline-block;margin-top:14px}.row{grid-template-columns:1fr;gap:4px}.number{text-align:left}.actions .action{width:100%}}
    </style>
</head>
<body>
<div class="top"></div>
<main>
    <div class="brand"><strong>ركن</strong><span>تعلم بدقيقة</span></div>
    <section class="card">
        <div class="head">
            <h1>التحقق من الشهادة</h1>
            <span class="status {{ $verification['status'] === 'revoked' ? 'revoked' : '' }}">{{ $verification['status_label'] }}</span>
        </div>
        <dl>
            <div class="row"><dt>صاحب الشهادة</dt><dd>{{ $verification['holder_name'] }}</dd></div>
            <div class="row"><dt>الكورس</dt><dd class="course">{{ $verification['course_name'] }}</dd></div>
            <div class="row"><dt>الإنجاز</dt><dd>{{ $verification['achievement'] }}</dd></div>
            <div class="row"><dt>نوع التحقق</dt><dd>{{ $verification['verification_label'] }}</dd></div>
            <div class="row"><dt>تاريخ الإتمام</dt><dd>{{ $verification['issued_at'] }}</dd></div>
            <div class="row"><dt>رقم الشهادة</dt><dd class="number">{{ $verification['public_id'] }}</dd></div>
        </dl>
        @if($verification['status'] === 'active' && $verification['artifact_url'])
            <div class="actions">
                <a class="action primary" href="{{ $verification['artifact_url'] }}" rel="noopener noreferrer">عرض الشهادة</a>
                @if($verification['pdf_url'])
                    <a class="action secondary" href="{{ $verification['pdf_url'] }}" rel="noopener noreferrer">تحميل PDF</a>
                @endif
            </div>
        @endif
    </section>
</main>
</body>
</html>
