@extends('layouts.landing')

@php
    $pageTitle = $setting?->{'seo_meta_title_'.$locale} ?: ($setting?->{'site_name_'.$locale} ?: 'Rokn');
    $hasDownloads = collect($downloadChannels ?? [])->filter()->isNotEmpty();
    $hasDirectDiscount = ($directDiscountPercent ?? 0) > 0;
    $headline = $designSetting->exists && filled($designSetting->{'slogan_1_'.$locale})
        ? $designSetting->{'slogan_1_'.$locale}
        : __('landing.hero_title');
    $headline = preg_split('/\s+/', trim($headline), 2);
@endphp

@section('title', $pageTitle)
@section('meta_description', $setting?->{'seo_meta_description_'.$locale} ?: __('landing.hero_description'))
@section('page_class', 'app-landing')

@section('content')
    <section class="landing-hero" aria-labelledby="hero-title">
        <div class="landing-container hero-grid">
            <div class="hero-copy">
                <h1 id="hero-title">
                    <span>{{ $headline[0] }}</span>
                    @if(isset($headline[1]))
                        {{ $headline[1] }}
                    @endif
                </h1>
                <p class="hero-description">{{ $designSetting->exists && filled($designSetting->{'slogan_2_'.$locale}) ? $designSetting->{'slogan_2_'.$locale} : __('landing.hero_description') }}</p>
            </div>

            <div class="hero-visual">
                @include('landing.partials.player-preview')
            </div>

            <div id="download" class="hero-download">
                @include('landing.partials.download-buttons')
            </div>
        </div>
    </section>

    <section class="learning-section" aria-label="{{ __('landing.learning_label') }}">
        <ul class="landing-container learning-points">
            @foreach(__('landing.learning_benefits') as $kind => $benefit)
                <li>
                    @switch($kind)
                        @case('explanations')
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8z"/></svg>
                            @break
                        @case('feedback')
                            <svg viewBox="0 0 39 39" aria-hidden="true"><path d="M6.38477 17.5588H25.5397M23.9435 23.9438L27.1359 27.1363L33.5209 20.7513M6.38477 11.1738H25.5397M6.38477 23.9438H19.1547"/></svg>
                            @break
                        @case('portfolio')
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="15" rx="3"/><path d="M9 5V3h6v2M3 11h18M10 14h4"/></svg>
                            @break
                    @endswitch
                    <span>{{ $benefit }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="recharge-section" aria-labelledby="recharge-title">
        <div class="landing-container recharge-panel">
            <img src="{{ asset('images/rokn-coin-minted.png') }}" alt="" width="80" height="80" loading="lazy">
            <div class="recharge-copy">
                <h2 id="recharge-title">{{ $hasDirectDiscount
                    ? __('landing.recharge_title', ['discount' => rtrim(rtrim(number_format($directDiscountPercent, 2, '.', ''), '0'), '.')])
                    : __('landing.recharge_action') }}</h2>
                <a class="download-button" href="{{ route('web-wallet.index') }}">{{ __('landing.recharge_action') }}</a>
            </div>
        </div>
    </section>

    @if($designSetting->show_how_platform_works && $howPlatformWorksVideoUrl)
        <section class="landing-how-it-works">
            <div class="landing-container">
                @php $howTitle = $designSetting->{'how_platform_works_title_'.$locale} ?: __('landing.how_it_works_default_title'); @endphp
                <h2 class="section-title">{{ $howTitle }}</h2>
                <div class="video-wrapper">
                    <iframe src="{{ $howPlatformWorksVideoUrl }}" title="{{ $howTitle }}" allowfullscreen
                            loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>
                </div>
            </div>
        </section>
    @endif

    @if($designSetting->exists && filled($designSetting->{'slogan_3_'.$locale}))
        <section class="download-note" aria-labelledby="download-title">
            <div class="landing-container">
                <h2 id="download-title">{{ $designSetting->{'slogan_3_'.$locale} }}</h2>
                <a href="#download" class="download-button">{{ __('landing.download_rokn') }}</a>
            </div>
        </section>
    @endif

    @if($hasDownloads)
        <aside class="download-dock" data-download-dock hidden aria-label="{{ __('landing.download_app') }}">
            <img src="{{ asset('images/landing/rokn-icon.webp') }}" alt="" width="40" height="40">
            <strong>Rokn</strong>
            <a href="#download" class="download-button" data-dock-link>{{ __('landing.download_rokn') }}</a>
        </aside>
    @endif
    <script src="{{ asset('js/landing.js') }}?v={{ filemtime(public_path('js/landing.js')) }}" defer></script>
@endsection
