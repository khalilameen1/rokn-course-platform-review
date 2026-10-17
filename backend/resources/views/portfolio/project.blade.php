@php($completed = $project['completed_at'] ? \Illuminate\Support\Carbon::parse($project['completed_at'])->locale('ar')->translatedFormat('F Y') : null)
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    @include('portfolio.partials.head', ['meta' => [
        'title' => $project['title'] . ' — ' . $profile['name'],
        'description' => \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/u', ' ', (string) ($project['description'] ?: ($profile['headline'] ?: 'مشروع من أعمال ' . $profile['name'] . ' على ركن')))), 200),
        'type' => 'article',
        'url' => $project['url'],
        'image' => $share_image,
    ]])
</head>
<body class="project-page">
<header class="hero"><div class="wrap">
    <a class="back" href="{{ $profile['public_url'] }}">
        <img src="{{ $profile['image_url'] ?: asset('images/avatar/customer_blank.png') }}" onerror="this.onerror=null;this.src='{{ asset('images/avatar/customer_blank.png') }}'" alt="" width="36" height="36">
        <span>كل أعمال {{ $profile['name'] }}</span>
    </a>
    <div style="margin-top:22px">
        <h1>{{ $project['title'] }}</h1>
        <div class="facts">
            <span>{{ $profile['name'] }}@if($profile['headline']) — {{ $profile['headline'] }}@endif</span>
            @if($project['role'])<span>الدور: {{ $project['role'] }}</span>@endif
            @if($completed)<span>{{ $completed }}</span>@endif
        </div>
        @if($project['external_url'])
            <div class="actions"><a class="button" href="{{ $project['external_url'] }}" target="_blank" rel="noopener noreferrer nofollow ugc">عرض المشروع على موقعه ↗</a></div>
        @endif
    </div>
</div></header>

<main class="wrap">
    <section aria-label="ملفات المشروع">
        <div class="media-stack">
            @foreach($project['media'] as $asset)
                @include('portfolio.partials.media', ['asset' => $asset, 'title' => $project['title'], 'gallery' => 'project', 'eager' => $loop->first])
            @endforeach
        </div>
    </section>

    @if($project['description'] || !empty($project['tools']))
    <section>
        @if($project['description'])<div class="description">{!! nl2br(e($project['description'])) !!}</div>@endif
        @if(!empty($project['tools']))
            <h2 style="font-size:18px;margin:26px 0 0">الأدوات والمهارات</h2>
            <div class="tools">@foreach($project['tools'] as $tool)<span>{{ $tool }}</span>@endforeach</div>
        @endif
    </section>
    @endif

    @if(!empty($more_projects))
    <section>
        <div class="section-head"><h2>أعمال أخرى</h2><a href="{{ $profile['public_url'] }}">عرض الكل</a></div>
        <div class="grid">
            @foreach($more_projects as $other)
                <article class="project">
                    @if($other['cover'] && $other['cover']['file_type'] === 'image')
                        <a class="cover" href="{{ $other['url'] }}" aria-label="{{ $other['title'] }}"><img src="{{ $other['cover']['image_url'] }}" alt="{{ $other['title'] }}" @if($other['cover']['width'] && $other['cover']['height']) width="{{ $other['cover']['width'] }}" height="{{ $other['cover']['height'] }}" @endif loading="lazy" decoding="async" onerror="this.remove()"></a>
                    @endif
                    <div class="project-body"><h3><a href="{{ $other['url'] }}">{{ $other['title'] }}</a></h3></div>
                </article>
            @endforeach
        </div>
    </section>
    @endif
</main>
<footer><div class="wrap">بورتفوليو على ركن
    <p><a href="{{ $profile['public_url'] }}#report">الإبلاغ عن محتوى</a> · <a href="{{ route('contact') }}">تواصل مع ركن</a></p>
</div></footer>
@include('portfolio.partials.lightbox')
</body>
</html>
