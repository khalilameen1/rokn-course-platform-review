{{-- Web adaptation of CourseChatOverlay / courseChat/styles.ts, with a fixed illustrative exchange. --}}
<dialog id="course-chat-demo" class="course-chat-demo" aria-labelledby="chat-demo-title">
    <div class="chat-sheet">
        <div class="chat-handle" aria-hidden="true"></div>
        <header class="chat-header">
            <div>
                <h2 id="chat-demo-title">{{ __('landing.chat_title') }}</h2>
            </div>
            <button type="button" class="chat-close" data-chat-close aria-label="{{ __('landing.chat_close') }}" autofocus>×</button>
        </header>
        <div class="chat-messages" role="region" aria-label="{{ __('landing.chat_conversation') }}" tabindex="0">
            <p class="chat-user-bubble">{{ __('landing.chat_question') }}</p>
            <div class="chat-assistant-bubble">
                <p class="chat-sender">{{ __('landing.chat_sender') }}</p>
                <p class="chat-answer">{{ __('landing.chat_answer') }}</p>
            </div>
        </div>
        <div class="chat-download-gate" data-chat-gate hidden>
            <h3 tabindex="-1" data-chat-gate-title>{{ __('landing.chat_download_title') }}</h3>
            <p>{{ __('landing.chat_download_hint') }}</p>
            <a class="download-button" href="#download" data-chat-download>{{ __('landing.download_app') }}</a>
        </div>
        <div class="chat-composer">
            <button type="button" class="chat-input" data-chat-use aria-label="{{ __('landing.chat_input_label') }}">{{ __('landing.chat_input') }}</button>
            <button type="button" class="chat-send" data-chat-use aria-label="{{ __('landing.chat_send') }}">
                <svg width="21" height="21" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="m20 4-8.1 16-2.3-6.5L4 10.1 20 4Z"/><path d="m9.6 13.5 4.3-4.2"/></svg>
            </button>
        </div>
    </div>
</dialog>
