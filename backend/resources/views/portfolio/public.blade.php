<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="theme-color" content="#07111f">
    <title>{{ $portfolio['profile']['name'] }} — بورتفوليو ركن</title>
    <style>
        :root{--bg:#07111f;--surface:#0d1a2b;--surface2:#122238;--line:rgba(255,255,255,.09);--text:#f6f8fb;--muted:#9babbe}
        *{box-sizing:border-box}html{background:var(--bg);scroll-behavior:smooth}body{margin:0;color:var(--text);background:radial-gradient(900px 440px at 85% -10%,rgba(52,120,246,.18),transparent 60%),var(--bg);font-family:"Cairo","Segoe UI",Tahoma,sans-serif;line-height:1.7}
        a{color:inherit}.wrap{width:min(1120px,calc(100% - 32px));margin:auto}.hero{padding:max(48px,env(safe-area-inset-top)) 0 32px;border-bottom:1px solid var(--line)}
        .identity{display:grid;grid-template-columns:112px 1fr;gap:24px;align-items:center}.avatar{width:112px;height:112px;border-radius:30px;object-fit:cover;background:var(--surface2);border:1px solid var(--line)}
        h1{font-size:clamp(30px,5vw,52px);line-height:1.15;margin:0 0 8px;letter-spacing:-.03em}.headline{font-size:clamp(16px,2.2vw,21px);color:#d7e0ec;margin:0}.muted{color:var(--muted)}
        .chips,.links{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}.chip,.link{border:1px solid var(--line);background:rgba(255,255,255,.035);padding:7px 12px;border-radius:999px;font-size:13px;text-decoration:none}
        section{padding:42px 0}.section-head{display:flex;align-items:end;justify-content:space-between;gap:16px;margin-bottom:18px}.section-head h2{font-size:26px;margin:0}.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:16px}
        .project{grid-column:span 6;overflow:hidden;border:1px solid var(--line);border-radius:24px;background:linear-gradient(180deg,var(--surface2),var(--surface));box-shadow:0 18px 60px rgba(0,0,0,.16)}
        .project.featured{grid-column:span 12}.project-media{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1px;background:var(--line)}.project-media.single{grid-template-columns:1fr}.project-asset{display:block;width:100%;aspect-ratio:16/9;object-fit:cover;background:#0a1524;border:0}.project-body{padding:22px}.project h3{font-size:21px;margin:0 0 7px}.eyebrow{font-size:12px;color:#8ab3ff;margin-bottom:7px}.tools{display:flex;gap:6px;flex-wrap:wrap;margin-top:16px}.tools span{font-size:12px;color:var(--muted);background:rgba(255,255,255,.045);padding:5px 9px;border-radius:8px}
        footer{border-top:1px solid var(--line);padding:26px 0 max(30px,env(safe-area-inset-bottom));color:var(--muted);font-size:13px}
        .report{margin-top:16px;max-width:560px}.report summary{cursor:pointer}.report label{display:block;margin:12px 0 4px}.report textarea,.report input{width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;background:var(--surface);color:var(--text);font:inherit}.report button{margin-top:12px;padding:10px 20px;border:0;border-radius:8px;background:#3478f6;color:white;font:inherit}.report-status{color:var(--text)}
        @media(max-width:720px){.wrap{width:min(100% - 24px,1120px)}.identity{grid-template-columns:76px 1fr;gap:15px}.avatar{width:76px;height:76px;border-radius:22px}.project,.project.featured{grid-column:span 12}.hero{padding-top:max(28px,env(safe-area-inset-top))}section{padding:30px 0}.project{border-radius:20px}.project-media{grid-template-columns:1fr}}
    </style>
</head>
<body>
<header class="hero"><div class="wrap">
    <div class="identity">
        <img class="avatar" src="{{ $portfolio['profile']['image_url'] ?: asset('images/avatar/customer_blank.png') }}" onerror="this.onerror=null;this.src='{{ asset('images/avatar/customer_blank.png') }}'" alt="{{ $portfolio['profile']['name'] }}">
        <div><h1>{{ $portfolio['profile']['name'] }}</h1><p class="headline">{{ $portfolio['profile']['headline'] ?: 'متعلم في ركن' }}</p>@if($portfolio['profile']['location'])<div class="muted">{{ $portfolio['profile']['location'] }}</div>@endif</div>
    </div>
    <div class="chips">@foreach(($portfolio['profile']['skills'] ?? []) as $skill)<span class="chip">{{ $skill }}</span>@endforeach</div>
    <div class="links">@foreach(($portfolio['profile']['links'] ?? []) as $link)<a class="link" href="{{ $link['url'] }}" rel="noopener noreferrer">{{ $link['label'] }} ↗</a>@endforeach</div>
</div></header>

<main class="wrap">
    @if($isAdminPreview ?? false)
        <p role="status">معاينة إدارية خاصة — لا تعيد هذه المعاينة نشر الأعمال أو تفعيل مشاركتها</p>
        @php($reviewLabels = ['pending' => 'بانتظار المراجعة', 'approved' => 'معتمد للمشاركة', 'rejected' => 'مرفوض للمشاركة', 'suspended' => 'المشاركة موقوفة'])
        <p>الحالة: {{ $reviewLabels[$portfolio['review']['status']] }} — النسخة {{ $portfolio['review']['revision'] }}</p>
        @if($portfolio['review']['status'] === 'suspended')<p>يبقى إيقاف المشاركة ساريًا بعد الاعتماد ويمكن رفعه من البلاغ المرتبط</p>@endif
        @if($portfolio['review']['rejection_reason'])<p>سبب الرفض: {{ $portfolio['review']['rejection_reason'] }}</p>@endif
        @if(session('success'))<p role="status">{{ session('success') }}</p>@endif
        @if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
        <p>راجع بيانات المعرض وكل الصور والفيديوهات والروابط قبل القرار وسيعيد أي تعديل على المحتوى المعروض المعرض إلى المراجعة</p>
        <form class="report" method="POST" action="{{ route('admin.portfolio-reviews.decide', $reviewUser) }}">
            @csrf
            <input type="hidden" name="revision" value="{{ $portfolio['review']['revision'] }}">
            <input type="hidden" name="snapshot_hash" value="{{ $portfolio['review']['snapshot_hash'] }}">
            <label for="review-reason">سبب الرفض لصاحب المعرض (مطلوب عند الرفض)</label>
            <textarea id="review-reason" name="reason" rows="3" maxlength="2000">{{ old('reason') }}</textarea>
            <button type="submit" name="decision" value="approved">اعتماد هذه النسخة للمشاركة</button>
            <button type="submit" name="decision" value="rejected">رفض المشاركة</button>
        </form>
        <p><a href="{{ route('admin.portfolio-reviews.index') }}">قائمة مراجعة المعارض</a></p>
    @endif
    @if(!empty($portfolio['projects']))
    <section><div class="section-head"><h2>المشروعات</h2></div><div class="grid">
        @foreach($portfolio['projects'] as $project)
        <article class="project {{ $project['is_featured'] ? 'featured' : '' }}">
            @php($media = collect($project['media'] ?? [])->filter(fn ($item) => ($item['file_type'] ?? null) === 'image' ? !empty($item['image_url']) : (($item['file_type'] ?? null) === 'video' && !empty($item['video_url'])))->values())
            @if($media->isNotEmpty())
                <div class="project-media {{ $media->count() === 1 ? 'single' : '' }}">
                    @foreach($media as $asset)
                        @if(($asset['file_type'] ?? null) === 'image')
                            <img class="project-asset" src="{{ $asset['image_url'] }}" onerror="this.remove()" alt="{{ $asset['caption'] ?: $project['title'] }}" loading="lazy">
                        @else
                            <iframe class="project-asset" src="{{ $asset['video_url'] }}" title="{{ $asset['caption'] ?: $project['title'] }}" loading="lazy" allow="encrypted-media; picture-in-picture" allowfullscreen referrerpolicy="no-referrer"></iframe>
                        @endif
                    @endforeach
                </div>
            @endif
            <div class="project-body">@if($project['course'])<div class="eyebrow">مشروع من كورس {{ $project['course']['name'] }}</div>@endif<h3>{{ $project['title'] }}</h3>@if($project['role'])<div class="muted">الدور: {{ $project['role'] }}</div>@endif<p class="muted">{{ $project['description'] }}</p><div class="tools">@foreach($project['tools'] as $tool)<span>{{ $tool }}</span>@endforeach</div>@if($project['external_url'])<p><a href="{{ $project['external_url'] }}" rel="noopener noreferrer">عرض المشروع ↗</a></p>@endif</div>
        </article>
        @endforeach
    </div></section>
    @endif

</main>
<footer><div class="wrap">بورتفوليو على ركن
    @unless($isAdminPreview ?? false)
    @if(session('portfolio_report_sent'))<p class="report-status" role="status">وصل بلاغك وسنراجع المحتوى</p>@endif
    @if($errors->any())<p class="report-status" role="alert">{{ $errors->first() }}</p>@endif
    <details class="report" @if($errors->any()) open @endif>
        <summary>الإبلاغ عن محتوى</summary>
        <form method="POST" action="{{ route('portfolio.report', $portfolio['profile']['slug']) }}">
            @csrf
            <label for="report-message">ما المشكلة؟</label>
            <textarea id="report-message" name="message" rows="3" minlength="5" maxlength="2000" required>{{ old('message') }}</textarea>
            <label for="report-email">بريدك للمتابعة (اختياري)</label>
            <input id="report-email" name="email" type="email" maxlength="255" autocomplete="email" value="{{ old('email') }}">
            <button type="submit">إرسال البلاغ</button>
        </form>
    </details>
    @endunless
    <p><a href="{{ route('contact') }}">تواصل معنا</a></p>
</div></footer>
</body>
</html>
