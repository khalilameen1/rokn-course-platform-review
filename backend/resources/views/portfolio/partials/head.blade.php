{{-- Shared <head> for public portfolio pages. Open Graph (ogp.me) + Twitter/X cards so WhatsApp, LinkedIn and X show a preview; preview crawlers read these even though the page stays noindex. --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow,noarchive">
<meta name="theme-color" content="#07111f">
<title>{{ $meta['title'] }}</title>
@if(!empty($meta['url']))
<meta name="description" content="{{ $meta['description'] }}">
<meta property="og:site_name" content="ركن">
<meta property="og:locale" content="ar_EG">
<meta property="og:type" content="{{ $meta['type'] }}">
<meta property="og:title" content="{{ $meta['title'] }}">
<meta property="og:description" content="{{ $meta['description'] }}">
<meta property="og:url" content="{{ $meta['url'] }}">
@if(!empty($meta['image']['url']))
<meta property="og:image" content="{{ $meta['image']['url'] }}">
<meta property="og:image:secure_url" content="{{ $meta['image']['url'] }}">
@if(!empty($meta['image']['width']) && !empty($meta['image']['height']))
<meta property="og:image:width" content="{{ $meta['image']['width'] }}">
<meta property="og:image:height" content="{{ $meta['image']['height'] }}">
@endif
<meta property="og:image:alt" content="{{ $meta['image']['alt'] }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="{{ $meta['image']['url'] }}">
<meta name="twitter:image:alt" content="{{ $meta['image']['alt'] }}">
@else
<meta name="twitter:card" content="summary">
@endif
<meta name="twitter:title" content="{{ $meta['title'] }}">
<meta name="twitter:description" content="{{ $meta['description'] }}">
@endif
@include('portfolio.partials.styles')
