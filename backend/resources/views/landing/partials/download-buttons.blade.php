@php
    $channels = array_filter(array_intersect_key($downloadChannels ?? [], array_flip(['play', 'appstore'])));
    $giftAmount = number_format((int) ($welcomeCoins ?? 0));
    if (app()->getLocale() === 'ar') {
        $giftAmount = strtr($giftAmount, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩', ',' => '٬']);
    }
    $stores = [
        'play' => ['name' => 'Google Play', 'image' => 'google-play.svg', 'width' => 135, 'height' => 40],
        'appstore' => ['name' => 'App Store', 'image' => 'app-store.svg', 'width' => 120, 'height' => 40],
    ];
@endphp

<div class="download-options{{ ($vertical ?? false) ? ' download-options--vertical' : '' }}" data-download-options>
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
                    <span class="store-availability">{{ __('landing.store_soon') }}</span>
                </span>
            @endif
        @endforeach
    </div>

    @if(!($channels['play'] ?? null) && !($channels['appstore'] ?? null))
        <p class="release-notice">{{ __('landing.release_unavailable') }}</p>
    @endif

    @if(($welcomeCoins ?? 0) > 0)
        <p class="welcome-gift">
            {{ __('landing.welcome_prefix') }}
            <span class="gift-value"><bdi dir="ltr">{{ $giftAmount }}</bdi><img src="{{ asset('images/rokn-coin-minted.png') }}" width="24" height="24" alt="{{ __('landing.coin_label') }}"></span>
            <span>{{ __('landing.welcome_label') }}</span>
        </p>
    @endif
</div>
