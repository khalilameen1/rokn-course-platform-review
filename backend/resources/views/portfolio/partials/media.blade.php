{{-- One approved file at its natural aspect ratio: no forced crop for portrait or 9:16 work. --}}
@php($alt = ($asset['caption'] ?? null) ?: $title)
@php($sized = !empty($asset['width']) && !empty($asset['height']))
<figure>
    @if(($asset['file_type'] ?? null) === 'image')
        {{-- Without JavaScript the link opens the full image; with it, the lightbox opens. --}}
        <a class="zoom" href="{{ $asset['image_url'] }}" target="_blank" rel="noopener noreferrer" data-lightbox="{{ $gallery }}" data-caption="{{ $asset['caption'] ?? '' }}">
            <img src="{{ $asset['image_url'] }}" alt="{{ $alt }}" @if($sized) width="{{ $asset['width'] }}" height="{{ $asset['height'] }}" @endif loading="{{ ($eager ?? false) ? 'eager' : 'lazy' }}" decoding="async" onerror="this.closest('figure').remove()">
        </a>
    @else
        {{-- Poster first; the player iframe loads only after a click. --}}
        <div class="video" @if($sized) style="aspect-ratio:{{ (int) $asset['width'] }} / {{ (int) $asset['height'] }}" @endif data-video-src="{{ $asset['video_url'] }}" data-video-title="{{ $alt }}">
            <a class="video-play" href="{{ $asset['video_url'] }}" target="_blank" rel="noopener noreferrer" aria-label="تشغيل الفيديو: {{ $alt }}">
                @if(!empty($asset['poster_url']))<img src="{{ $asset['poster_url'] }}" alt="" loading="lazy" decoding="async" onerror="this.remove()">@endif
                <span class="play-icon" aria-hidden="true">&#9654;</span>
            </a>
        </div>
    @endif
    @if(!empty($asset['caption']) && ($showCaption ?? true))<figcaption>{{ $asset['caption'] }}</figcaption>@endif
</figure>
