@extends('layouts.landing')

@php
    $pageTitle = $setting?->{'seo_meta_title_'.$locale} ?: ($setting?->{'site_name_'.$locale} ?: 'Rokn');
    $hasDownloads = collect($downloadChannels ?? [])->filter()->isNotEmpty();
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
                @if(($welcomeCoins ?? 0) > 0)
                    <p class="welcome-gift">
                        <img src="{{ asset('images/rokn-coin-minted.png') }}" width="40" height="40" alt="">
                        <span><strong>{{ __('landing.welcome_title', ['coins' => number_format($welcomeCoins)]) }}</strong>
                        <span>{{ __('landing.welcome_description') }}</span></span>
                    </p>
                @endif
                    @include('landing.partials.download-buttons')
            </div>
        </div>
    </section>

    <section class="recharge-section" aria-labelledby="recharge-title">
        <div class="landing-container recharge-panel">
            <div class="recharge-copy">
                <p class="eyebrow">{{ __('landing.recharge_label') }}</p>
                <h2 id="recharge-title">{{ __('landing.recharge_title') }}</h2>
                <p>{{ __('landing.recharge_description') }}</p>
                <a class="download-button" href="{{ route('web-wallet.index') }}">{{ __('landing.recharge_action') }}</a>
                <p class="recharge-note">{{ __('landing.recharge_note') }}</p>
            </div>
            <div class="recharge-art">
                <img src="{{ asset('images/rokn-coin-minted.png') }}" alt="" width="160" height="160" loading="lazy">
                @if(($directDiscountPercent ?? 0) > 0)
                    <p><strong>{{ rtrim(rtrim(number_format($directDiscountPercent, 2, '.', ''), '0'), '.') }}<span>%</span></strong>
                    <span>{{ __('landing.recharge_saving') }}</span></p>
                @endif
            </div>
        </div>
    </section>

    <section class="learning-section" aria-label="{{ __('landing.learning_label') }}">
        <div class="landing-container learning-points">
            @foreach(['ask', 'practice', 'portfolio'] as $feature)
                <article>
                    <h2>{{ __('landing.'.$feature.'_title') }}</h2>
                    <p>{{ __('landing.'.$feature.'_description') }}</p>
                </article>
            @endforeach
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

    <section class="download-section" aria-labelledby="download-title">
        <div class="landing-container download-finish">
            <img src="{{ asset('images/landing/rokn-icon.webp') }}" alt="" width="64" height="64" loading="lazy">
            <h2 id="download-title">{{ __('landing.download_title') }}</h2>
            <p>{{ $designSetting->exists && filled($designSetting->{'slogan_3_'.$locale}) ? $designSetting->{'slogan_3_'.$locale} : __('landing.download_description') }}</p>
            @include('landing.partials.download-buttons')
        </div>
    </section>

    @if($hasDownloads)
        <aside class="download-dock" data-download-dock hidden aria-label="{{ __('landing.download_app') }}">
            <img src="{{ asset('images/landing/rokn-icon.webp') }}" alt="" width="40" height="40">
            <strong>Rokn</strong>
            <a href="#download" class="download-button" data-dock-link>{{ __('landing.download_rokn') }}</a>
        </aside>
    @endif
    <script src="{{ asset('js/landing.js') }}?v={{ filemtime(public_path('js/landing.js')) }}" defer></script>
@endsection
