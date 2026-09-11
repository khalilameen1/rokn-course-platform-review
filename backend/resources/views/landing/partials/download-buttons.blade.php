@php
    $channels = array_filter($downloadChannels ?? []);
    $discountLabel = rtrim(rtrim(number_format((float) ($directDiscountPercent ?? 0), 2, '.', ''), '0'), '.');
    $stores = [
        'appstore' => ['name' => 'App Store', 'image' => 'app-store.svg', 'width' => 120, 'height' => 40],
        'play' => ['name' => 'Google Play', 'image' => 'google-play.svg', 'width' => 135, 'height' => 40],
    ];
@endphp

<div class="download-options{{ ($vertical ?? false) ? ' download-options--vertical' : '' }}" data-download-options>
    <div class="direct-download-option{{ ($directDiscountPercent ?? 0) > 0 ? ' direct-download-option--offer' : '' }}">
        @if(($directDiscountPercent ?? 0) > 0)
            <span class="direct-saving">{{ __('landing.direct_saving', ['discount' => $discountLabel]) }}</span>
        @endif
    @if($channels['direct'] ?? null)
        <a href="{{ $channels['direct'] }}" class="store-btn store-btn--direct" data-channel="direct">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m-5-5 5 5 5-5M5 17v4h14v-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span>{{ __('landing.direct_download') }} <bdi>APK</bdi></span>
        </a>
        @if($directDownloadIsPreview ?? false)
            <p class="direct-caption">{{ __('landing.preview_download') }}</p>
        @endif
    @else
        <span class="store-btn store-btn--direct" aria-disabled="true">{{ __('landing.direct_download') }} <bdi>APK</bdi></span>
        <p class="direct-caption">{{ __('landing.direct_pending') }}</p>
    @endif
    </div>
    <div class="store-buttons">
        @foreach($stores as $channel => $store)
            @if($channels[$channel] ?? null)
                <a href="{{ $channels[$channel] }}" class="store-btn" data-channel="{{ $channel }}" rel="noopener">
                    <img src="{{ asset('images/landing/'.$store['image']) }}"
                         width="{{ $store['width'] }}" height="{{ $store['height'] }}"
                         alt="{{ __('landing.download_on').' '.$store['name'] }}">
                </a>
            @else
                <span class="store-btn" aria-disabled="true">
                    <img src="{{ asset('images/landing/'.$store['image']) }}"
                         width="{{ $store['width'] }}" height="{{ $store['height'] }}"
                         alt="{{ __('landing.store_pending', ['store' => $store['name']]) }}">
                </span>
            @endif
        @endforeach
    </div>

    @if(!($channels['play'] ?? null) && !($channels['appstore'] ?? null))
        <p class="release-notice">{{ __('landing.release_unavailable') }}</p>
    @endif

    @if(($welcomeCoins ?? 0) > 0)
        <p class="welcome-gift">
            <img src="{{ asset('images/rokn-coin-minted.png') }}" width="24" height="24" alt="">
            <strong>{{ __('landing.welcome_title', ['coins' => number_format($welcomeCoins)]) }}</strong>
        </p>
    @endif
</div>
