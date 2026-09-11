@extends('layouts.landing')

@section('title', $document['title'] . ' — ' . ($setting ? $setting->{'site_name_' . $locale} : 'Rokn'))
@section('meta_description', $document['meta_description'])

@section('content')
    <div class="page-content legal-page">
        <h1>{{ $document['heading'] }}</h1>
        <article class="policy-content">
            @if(!empty($managedBody))
                @foreach(preg_split('/\R+/u', trim($managedBody)) as $paragraph)
                    <p>{{ $paragraph }}</p>
                @endforeach
            @else
                @if(!empty($document['last_updated']))
                    <p class="terms-meta">{{ $document['last_updated'] }}</p>
                @endif
                <h2>{{ $document['intro_title'] }}</h2>
                <p>{{ $document['intro_text'] }}</p>
                @foreach($document['sections'] as $section)
                    <section>
                        <h2>{{ $section['title'] }}</h2>
                        @foreach((array) ($section['body'] ?? []) as $paragraph)
                            <p>{{ $paragraph }}</p>
                        @endforeach
                    </section>
                @endforeach
                <p class="policy-closing">{{ $document['closing'] }}</p>
            @endif
        </article>
        <nav class="policy-links" aria-label="{{ $document['title'] }}">
            @if($page === 'terms')
                <a href="{{ route('privacy') }}">{{ __('privacy.title') }}</a>
                <a href="{{ route('returns-policy') }}">{{ __('returns.title') }}</a>
            @endif
            <a href="{{ route('contact') }}">{{ $document['contact_label'] }}</a>
        </nav>
    </div>
@endsection
