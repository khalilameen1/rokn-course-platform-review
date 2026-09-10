@extends('layouts.landing')

@php
    $pageTitle = $setting?->{'seo_meta_title_'.$locale} ?: ($setting?->{'site_name_'.$locale} ?: 'Rokn');
    $hasDownloads = collect($downloadChannels ?? [])->filter()->isNotEmpty();
    $headline = preg_split('/\s+/', trim($designSetting->{'slogan_1_'.$locale} ?: __('landing.hero_title')), 2);
@endphp

@section('title', $pageTitle)
@section('meta_description', $setting?->{'seo_meta_description_'.$locale} ?: __('landing.hero_description'))
@section('page_class', 'app-landing')

@section('content')
    <section class="landing-hero" aria-labelledby="hero-title">
        <div class="landing-container hero-grid">
            <div class="hero-copy">
                <p class="eyebrow">{{ __('landing.hero_eyebrow') }}</p>
                <h1 id="hero-title">
                    {{ $headline[0] }}
                    @if(isset($headline[1]))
                        <span>{{ $headline[1] }}</span>
                    @endif
                </h1>
                <p class="hero-description">{{ $designSetting->exists && filled($designSetting->{'slogan_2_'.$locale}) ? $designSetting->{'slogan_2_'.$locale} : __('landing.hero_description') }}</p>
                <div id="download" class="hero-download">
                    @include('landing.partials.download-buttons')
                </div>
                <p class="download-note">{{ __('landing.preview_note') }}</p>
            </div>
            <figure class="app-preview home-preview">
                <img src="{{ asset('images/landing/rokn-home.webp') }}"
                     width="540" height="1212" fetchpriority="high"
                     alt="{{ __('landing.home_screenshot_alt') }}">
                <figcaption>{{ __('landing.inside_app') }}</figcaption>
            </figure>
        </div>
    </section>

    <section class="learning-section" aria-labelledby="learning-title">
        <div class="landing-container learning-grid">
            <div class="learning-copy">
                <h2 id="learning-title">{{ __('landing.learning_title') }}</h2>
                <p class="section-lead">{{ __('landing.learning_description') }}</p>
                <dl class="learning-points">
                    <div>
                        <dt>{{ __('landing.watch_title') }}</dt>
                        <dd>{{ __('landing.watch_description') }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('landing.ask_title') }}</dt>
                        <dd>{{ __('landing.ask_description') }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('landing.keep_title') }}</dt>
                        <dd>{{ __('landing.keep_description') }}</dd>
                    </div>
                </dl>
            </div>
            <figure class="app-preview lesson-preview">
                <img src="{{ asset('images/landing/rokn-lesson.webp') }}"
                     width="540" height="1212" loading="lazy" decoding="async"
                     alt="{{ __('landing.lesson_screenshot_alt') }}">
            </figure>
        </div>
    </section>

    <section class="outcome-section" aria-labelledby="outcome-title">
        <div class="landing-container outcome-grid">
            <h2 id="outcome-title">{{ __('landing.outcome_title') }}</h2>
            <div>
                <p class="section-lead">{{ __('landing.outcome_description') }}</p>
                <p class="muted">{{ __('landing.outcome_detail') }}</p>
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
            <div>
                <strong>Rokn</strong>
                <span>{{ __('landing.hero_eyebrow') }}</span>
            </div>
            <a href="#download" class="download-button" data-dock-link>{{ __('landing.download_rokn') }}</a>
        </aside>
    @endif
    <script src="{{ asset('js/landing.js') }}?v={{ filemtime(public_path('js/landing.js')) }}" defer></script>
@endsection
