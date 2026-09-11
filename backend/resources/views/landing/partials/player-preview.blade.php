<div class="player-showcase">
    <div class="preview-player">
        <figure class="preview-scene">
            <img src="{{ asset('images/landing/photography.webp') }}" width="432" height="768"
                 fetchpriority="high" alt="{{ __('landing.subject_photography_alt') }}">
            <figcaption>
                <strong>{{ __('landing.reel_photography') }}</strong>
            </figcaption>
        </figure>
        {{-- A visual presentation of the app controls, not an embedded student session. --}}
        <div class="player-chrome" aria-hidden="true">
            <div class="player-top"><svg viewBox="0 0 24 24"><path d="m9 5 7 7-7 7"/></svg></div>
            <div class="player-tools">
                <span><svg viewBox="0 0 24 24"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8z"/></svg>{{ __('landing.player_ask') }}</span>
                <span><svg viewBox="0 0 24 24"><path d="M19 21l-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>{{ __('landing.player_save') }}</span>
                <span><svg viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>{{ __('landing.player_index') }}</span>
            </div>
            <div class="player-timeline"><span></span></div>
        </div>
    </div>
</div>
