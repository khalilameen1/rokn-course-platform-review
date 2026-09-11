@extends('layouts.landing')

@section('title', __('contact.title') . ' — ' . ($setting ? $setting->{'site_name_' . $locale} : 'Rokn'))
@section('meta_description', __('contact.meta_description'))

@section('content')
    <div class="page-content">
        <h1>{{ __('contact.heading') }}</h1>
        <p class="intro-text">{{ __('contact.intro') }}</p>
        <a href="{{ \App\Support\RoknAppLink::normalize('/contact') }}" class="download-button">{{ __('contact.open_app') }}</a>

        @php
            $email = $publicSettings['support_contacts']['email'] ?? null;
            $phone = $publicSettings['support_contacts']['phone'] ?? null;
            $hasEmail = filled($email);
            $hasPhone = filled($phone);
            $contactSocials = collect($publicSettings['social_media'] ?? [])->filter();
            $hasAnyContact = $hasEmail || $hasPhone || $contactSocials->isNotEmpty();
        @endphp

        @if($hasAnyContact)
            <div class="contact-grid">
                @if($hasEmail)
                    <div class="contact-card">
                        <div class="contact-icon">&#9993;</div>
                        <h3>{{ __('contact.email_label') }}</h3>
                        <p><a href="mailto:{{ $email }}">{{ $email }}</a></p>
                    </div>
                @endif

                @if($hasPhone)
                    <div class="contact-card">
                        <div class="contact-icon">&#9742;</div>
                        <h3>{{ __('contact.phone_label') }}</h3>
                        <p><a href="tel:{{ $phone }}">{{ $phone }}</a></p>
                    </div>
                @endif
            </div>

            @if($contactSocials->isNotEmpty())
                <h2>{{ __('contact.social_title') }}</h2>
                <div class="social-links" style="justify-content: center;">
                    @if($contactSocials->has('facebook'))
                        <a href="{{ $contactSocials['facebook'] }}" target="_blank" rel="noopener noreferrer" aria-label="Facebook" style="background: var(--rokn-primary-light); color: var(--rokn-white);"><svg viewBox="0 0 24 24"><path d="M22 12c0-5.52-4.48-10-10-10S2 6.48 2 12c0 4.84 3.44 8.87 8 9.8V15H8v-3h2V9.5C10 7.57 11.57 6 13.5 6H16v3h-2c-.55 0-1 .45-1 1v2h3v3h-3v6.95c5.05-.5 9-4.76 9-9.95z"/></svg></a>
                    @endif
                    @if($contactSocials->has('instagram'))
                        <a href="{{ $contactSocials['instagram'] }}" target="_blank" rel="noopener noreferrer" aria-label="Instagram" style="background: var(--rokn-primary-light); color: var(--rokn-white);"><svg viewBox="0 0 24 24"><path d="M7.8 2h8.4C19.4 2 22 4.6 22 7.8v8.4a5.8 5.8 0 0 1-5.8 5.8H7.8C4.6 22 2 19.4 2 16.2V7.8A5.8 5.8 0 0 1 7.8 2m-.2 2A3.6 3.6 0 0 0 4 7.6v8.8C4 18.39 5.61 20 7.6 20h8.8a3.6 3.6 0 0 0 3.6-3.6V7.6C20 5.61 18.39 4 16.4 4H7.6m9.65 1.5a1.25 1.25 0 0 1 1.25 1.25A1.25 1.25 0 0 1 17.25 8 1.25 1.25 0 0 1 16 6.75a1.25 1.25 0 0 1 1.25-1.25M12 7a5 5 0 0 1 5 5 5 5 0 0 1-5 5 5 5 0 0 1-5-5 5 5 0 0 1 5-5m0 2a3 3 0 0 0-3 3 3 3 0 0 0 3 3 3 3 0 0 0 3-3 3 3 0 0 0-3-3z"/></svg></a>
                    @endif
                    @if($contactSocials->has('youtube'))
                        <a href="{{ $contactSocials['youtube'] }}" target="_blank" rel="noopener noreferrer" aria-label="YouTube" style="background: var(--rokn-primary-light); color: var(--rokn-white);"><svg viewBox="0 0 24 24"><path d="M10 15l5.19-3L10 9v6m11.56-7.83c.13.47.22 1.1.28 1.9.07.8.1 1.49.1 2.09L22 12c0 2.19-.16 3.8-.44 4.83-.25.9-.83 1.48-1.73 1.73-.47.13-1.33.22-2.65.28-1.3.07-2.49.1-3.59.1L12 19c-4.19 0-6.8-.16-7.83-.44-.9-.25-1.48-.83-1.73-1.73-.13-.47-.22-1.1-.28-1.9-.07-.8-.1-1.49-.1-2.09L2 12c0-2.19.16-3.8.44-4.83.25-.9.83-1.48 1.73-1.73.47-.13 1.33-.22 2.65-.28 1.3-.07 2.49-.1 3.59-.1L12 5c4.19 0 6.8.16 7.83.44.9.25 1.48.83 1.73 1.73z"/></svg></a>
                    @endif
                    @if($contactSocials->has('tiktok'))
                        <a href="{{ $contactSocials['tiktok'] }}" target="_blank" rel="noopener noreferrer" aria-label="TikTok" style="background: var(--rokn-primary-light); color: var(--rokn-white);"><svg viewBox="0 0 24 24"><path d="M16.6 5.82s.51.5 0 0A4.28 4.28 0 0 1 15.54 3h-3.09v12.4a2.59 2.59 0 0 1-2.59 2.5c-1.42 0-2.6-1.16-2.6-2.6 0-1.72 1.66-3.01 3.37-2.48V9.66c-3.45-.46-6.47 2.22-6.47 5.64 0 3.33 2.76 5.7 5.69 5.7 3.14 0 5.69-2.55 5.69-5.7V9.01a7.35 7.35 0 0 0 4.3 1.38V7.3s-1.88.09-3.24-1.48z"/></svg></a>
                    @endif
                    @if($contactSocials->has('whatsapp'))
                        <a href="{{ $contactSocials['whatsapp'] }}" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp" style="background: var(--rokn-primary-light); color: var(--rokn-white);"><svg viewBox="0 0 24 24"><path d="M17.47 14.38c-.29-.15-1.7-.84-1.96-.93-.27-.1-.46-.15-.66.15-.2.29-.76.93-.93 1.12-.17.2-.34.22-.63.07-.29-.14-1.23-.45-2.35-1.44-.87-.77-1.46-1.72-1.63-2.01-.17-.29-.02-.45.13-.6.13-.13.29-.34.44-.51.14-.17.2-.29.29-.49.1-.2.05-.37-.02-.51-.07-.15-.66-1.59-.9-2.18-.24-.57-.48-.49-.66-.5h-.56c-.2 0-.51.07-.78.37s-1.02 1-1.02 2.44 1.05 2.83 1.2 3.02c.14.2 2.06 3.14 4.99 4.4.7.3 1.24.48 1.66.62.7.22 1.34.19 1.84.12.56-.08 1.7-.7 1.94-1.37.24-.68.24-1.26.17-1.38-.08-.12-.27-.2-.56-.34m-5.42 7.4h-.03a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.73.98.99-3.64-.24-.37A9.86 9.86 0 0 1 2.18 12c0-5.46 4.44-9.9 9.9-9.9 2.64 0 5.12 1.03 6.99 2.9a9.83 9.83 0 0 1 2.9 6.99c-.01 5.46-4.45 9.9-9.92 9.9M20.52 3.47A11.84 11.84 0 0 0 12.05 0C5.49 0 .16 5.33.16 11.88c0 2.1.55 4.14 1.58 5.95L0 24l6.3-1.65a11.87 11.87 0 0 0 5.69 1.45h.01c6.55 0 11.88-5.33 11.89-11.89a11.81 11.81 0 0 0-3.37-8.44z"/></svg></a>
                    @endif
                    @if($contactSocials->has('telegram'))
                        <a href="{{ $contactSocials['telegram'] }}" target="_blank" rel="noopener noreferrer" aria-label="Telegram" style="background: var(--rokn-primary-light); color: var(--rokn-white);"><svg viewBox="0 0 24 24"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zm6.93 6.54l-2.13 10.05c-.16.72-.58.89-1.17.56l-3.24-2.39-1.56 1.5c-.17.17-.32.32-.65.32l.23-3.28 5.96-5.39c.26-.23-.06-.36-.4-.13l-7.37 4.64-3.17-1c-.69-.22-.7-.69.14-.99l12.37-4.76c.58-.21 1.08.14.89.99z"/></svg></a>
                    @endif
                </div>
            @endif
        @endif
    </div>
@endsection
