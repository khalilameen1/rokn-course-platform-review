{{-- The learner's own profile links as contact buttons (escaped, HTTPS only, WhatsApp as wa.me). --}}
@if(!empty($links))
<nav class="contacts" aria-label="تواصل مع {{ $name }}">
    @foreach($links as $link)
        <a class="contact contact-{{ $link['type'] }}" href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer nofollow ugc">
            <span class="contact-type">{{ $link['type_label'] }}</span>@if($link['label'] !== $link['type_label'])<span>{{ $link['label'] }}</span>@endif
        </a>
    @endforeach
</nav>
@endif
