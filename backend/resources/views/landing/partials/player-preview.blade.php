<div class="player-showcase">
    <div class="preview-player">
        <figure class="preview-scene motion-lesson">
            <p class="motion-lesson-headline">
                <span class="motion-lesson-lead">{{ __('landing.lesson_likes_lead') }}</span>
                <span class="motion-lesson-bridge">{{ __('landing.lesson_likes_bridge') }}</span>
                <strong class="motion-lesson-result">{{ __('landing.lesson_likes_result') }}</strong>
            </p>
            {{-- Lucide thumbs-up / shopping-bag paths; ISC/MIT notices in resources/legal/frontend/LUCIDE-ICONS.txt. --}}
            <svg class="motion-comparison" viewBox="0 0 180 204" fill="none" aria-hidden="true">
                <g class="motion-like">
                    <rect x="9" y="10" width="84" height="84" rx="26" fill="#c4b2ed" transform="rotate(-12 51 52)"/>
                    <g transform="translate(29 27) scale(1.9)" stroke="#252535" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/>
                        <path d="M7 10v12"/>
                    </g>
                </g>
                <g class="motion-not-equal" stroke="#252535" stroke-width="3.5" stroke-linecap="round">
                    <path d="M76 99h26M76 108h26"/>
                    <path class="motion-slash" d="m97 91-16 25"/>
                </g>
                <g class="motion-sale">
                    <circle cx="118" cy="153" r="41" fill="#ef6c44"/>
                    <g transform="translate(96 130) scale(1.85)" stroke="#252535" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M16 10a4 4 0 0 1-8 0"/>
                        <path d="M3.103 6.034h17.794"/>
                        <path d="M3.4 5.467a2 2 0 0 0-.4 1.2V20a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6.667a2 2 0 0 0-.4-1.2l-2-2.667A2 2 0 0 0 17 2H7a2 2 0 0 0-1.6.8z"/>
                    </g>
                </g>
            </svg>
            <figcaption>
                <strong>{{ __('landing.reel_likes_vs_sales') }}</strong>
            </figcaption>
        </figure>
        {{-- Chat opens a fixed demo; the remaining controls illustrate the native player. --}}
        <div class="player-chrome">
            <div class="player-tools">
                {{-- Native FeedActions geometry at 390px, scaled to this frame; plain treatment for the light demo artwork. --}}
                <button type="button" class="player-chat-action" data-chat-open aria-label="{{ __('landing.chat_open') }}" aria-haspopup="dialog" aria-controls="course-chat-demo" hidden>
                    <span class="player-action-icon"><svg viewBox="0 0 28 28" aria-hidden="true"><path d="M5.1 5.5h17.8c1.3 0 2.3 1 2.3 2.3v10c0 1.3-1 2.3-2.3 2.3h-8L8 24.5v-4.4H5.1c-1.3 0-2.3-1-2.3-2.3v-10c0-1.3 1-2.3 2.3-2.3Z"/><path d="M8.5 12.9h.1m5.3 0h.1m5.3 0h.1" stroke-width="2.6"/></svg></span>
                    <span class="player-action-label">{{ __('landing.player_ask') }}</span>
                </button>
                <span aria-hidden="true">
                    <span class="player-action-icon"><svg viewBox="0 0 28 28"><path d="M7 5.2c0-1 .8-1.8 1.8-1.8h10.4c1 0 1.8.8 1.8 1.8v19.4l-7-4.3-7 4.3V5.2Z"/></svg></span>
                    <span class="player-action-label">{{ __('landing.player_save') }}</span>
                </span>
                <span aria-hidden="true">
                    @php [$reelPosition, $reelTotal] = explode('/', __('landing.player_position'), 2); @endphp
                    <span class="player-action-icon player-counter">
                        <span class="player-counter-values" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}"><bdi dir="ltr">{{ $reelPosition }}</bdi><span>/</span><bdi dir="ltr">{{ $reelTotal }}</bdi></span>
                    </span>
                    <span class="player-action-label">{{ __('landing.player_index') }}</span>
                </span>
            </div>
            <div class="player-timeline" aria-hidden="true"><span></span></div>
        </div>
    </div>
</div>
