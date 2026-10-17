@php($isAdminPreview = $isAdminPreview ?? false)
@php($profile = $portfolio['profile'])
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    @include('portfolio.partials.head', ['meta' => [
        'title' => $profile['name'] . ' — بورتفوليو ركن',
        'description' => \Illuminate\Support\Str::limit(trim((string) ($profile['headline'] ?: 'أعمال ' . $profile['name'] . ' على ركن')), 200),
        'type' => 'profile',
        'url' => $isAdminPreview ? null : $profile['public_url'],
        'image' => $portfolio['share_image'] ?? null,
    ]])
</head>
<body>
<header class="hero"><div class="wrap">
    <div class="identity">
        <img class="avatar" src="{{ $profile['image_url'] ?: asset('images/avatar/customer_blank.png') }}" onerror="this.onerror=null;this.src='{{ asset('images/avatar/customer_blank.png') }}'" alt="{{ $profile['name'] }}" width="112" height="112">
        <div><h1>{{ $profile['name'] }}</h1><p class="headline">{{ $profile['headline'] ?: 'متعلم في ركن' }}</p></div>
    </div>
    @if(!empty($profile['skills']))<div class="chips">@foreach($profile['skills'] as $skill)<span class="chip">{{ $skill }}</span>@endforeach</div>@endif
</div></header>

<main class="wrap">
    @if($isAdminPreview)
        <p role="status">معاينة إدارية خاصة — لا تعيد هذه المعاينة نشر الأعمال أو تفعيل مشاركتها</p>
        @php($reviewLabels = ['pending' => 'بانتظار المراجعة', 'approved' => 'معتمد للمشاركة', 'rejected' => 'مرفوض للمشاركة', 'suspended' => 'المشاركة موقوفة'])
        <p>الحالة: {{ $reviewLabels[$portfolio['review']['status']] }} — النسخة {{ $portfolio['review']['revision'] }}</p>
        @if($portfolio['review']['published'] ?? false)<p>النسخة المعتمدة السابقة ظاهرة على الرابط العام حتى تعتمد هذه النسخة</p>@endif
        @if($portfolio['review']['status'] === 'suspended')<p>يبقى إيقاف المشاركة ساريًا بعد الاعتماد ويمكن رفعه من البلاغ المرتبط</p>@endif
        @if($portfolio['review']['rejection_reason'])<p>سبب الرفض: {{ $portfolio['review']['rejection_reason'] }}</p>@endif
        @if(session('success'))<p role="status">{{ session('success') }}</p>@endif
        @if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
        <p>راجع بيانات المعرض وكل الصور والفيديوهات والروابط قبل القرار. أي تعديل لاحق على المحتوى المعروض يرجع للمراجعة، بينما تفضل آخر نسخة معتمدة ظاهرة</p>
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
            @if($isAdminPreview)
                {{-- Reviewers see every file of the draft, uncropped. --}}
                @if(!empty($project['media']))
                    <div class="media-stack compact">
                        @foreach($project['media'] as $asset)
                            @include('portfolio.partials.media', ['asset' => $asset, 'title' => $project['title'], 'gallery' => 'project-' . $loop->parent->index])
                        @endforeach
                    </div>
                @endif
            @elseif($project['cover'])
                {{-- Behance-style card: the cover opens the project page. --}}
                @php($cover = $project['cover'])
                <a class="cover" href="{{ $project['url'] ?? '#' }}" aria-label="{{ $project['title'] }}">
                    @if($cover['file_type'] === 'image')
                        <img src="{{ $cover['image_url'] }}" alt="{{ ($cover['caption'] ?? null) ?: $project['title'] }}" @if($cover['width'] && $cover['height']) width="{{ $cover['width'] }}" height="{{ $cover['height'] }}" @endif loading="{{ $loop->first ? 'eager' : 'lazy' }}" decoding="async" onerror="this.remove()">
                    @else
                        <span class="video" @if($cover['width'] && $cover['height']) style="aspect-ratio:{{ (int) $cover['width'] }} / {{ (int) $cover['height'] }}" @endif>
                            @if(!empty($cover['poster_url']))<img src="{{ $cover['poster_url'] }}" alt="" loading="lazy" decoding="async" onerror="this.remove()" style="position:absolute;inset:0;width:100%;height:100%;object-fit:contain">@endif
                            <span class="video-play" aria-hidden="true"><span class="play-icon">&#9654;</span></span>
                        </span>
                    @endif
                    @if(count($project['media']) > 1)<span class="cover-count">{{ count($project['media']) }} ملفات</span>@endif
                </a>
            @endif
            <div class="project-body">
                {{-- The public page presents the learner's own work, not a course record. --}}
                @if($isAdminPreview && $project['course'])<div class="eyebrow">من كورس {{ $project['course']['name'] }}</div>@endif
                <h3>@if($project['url'])<a href="{{ $project['url'] }}">{{ $project['title'] }}</a>@else{{ $project['title'] }}@endif</h3>
                @if($project['role'])<div class="muted">الدور: {{ $project['role'] }}</div>@endif
                @if($project['description'])<p class="muted {{ $isAdminPreview ? '' : 'summary' }}">{{ $project['description'] }}</p>@endif
                @if(!empty($project['tools']))<div class="tools" aria-label="الأدوات والمهارات">@foreach($project['tools'] as $tool)<span>{{ $tool }}</span>@endforeach</div>@endif
                <div class="actions">
                    @if($project['url'])<a class="button secondary" href="{{ $project['url'] }}">عرض المشروع كامل</a>@endif
                    @if($project['external_url'])<a class="button secondary" href="{{ $project['external_url'] }}" target="_blank" rel="noopener noreferrer nofollow ugc">رابط خارجي ↗</a>@endif
                </div>
            </div>
        </article>
        @endforeach
    </div></section>
    @endif
</main>
<footer><div class="wrap">بورتفوليو على ركن
    @unless($isAdminPreview)
    @if(session('portfolio_report_sent'))<p class="report-status" role="status">وصل بلاغك وسنراجع المحتوى</p>@endif
    @if($errors->any())<p class="report-status" role="alert">{{ $errors->first() }}</p>@endif
    <details class="report" id="report" @if($errors->any()) open @endif>
        <summary>الإبلاغ عن محتوى</summary>
        <form method="POST" action="{{ route('portfolio.report', $profile['slug']) }}">
            @csrf
            <label for="report-message">ما المشكلة؟</label>
            <textarea id="report-message" name="message" rows="3" minlength="5" maxlength="2000" required>{{ old('message') }}</textarea>
            <label for="report-email">بريدك للمتابعة (اختياري)</label>
            <input id="report-email" name="email" type="email" maxlength="255" autocomplete="email" value="{{ old('email') }}">
            <button type="submit">إرسال البلاغ</button>
        </form>
    </details>
    @endunless
    <p><a href="{{ route('contact') }}">تواصل مع ركن</a></p>
</div></footer>
@include('portfolio.partials.lightbox')
</body>
</html>
