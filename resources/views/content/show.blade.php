@extends('content.layout')

@section('title', $article['title'])
@section('description', $article['description'])
@section('canonical', $article['url'])
@section('og_type', 'article')
@if ($article['image'])
    @section('og_image', url($article['image']))
@endif

@push('head')
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => $kind === 'blog' ? 'BlogPosting' : 'Article',
        'headline' => $article['title'],
        'description' => $article['description'],
        'datePublished' => $article['date']->toDateString(),
        'image' => $article['image'] ? url($article['image']) : null,
        'author' => ['@type' => 'Person', 'name' => 'Jan Anders'],
        'publisher' => ['@type' => 'Organization', 'name' => 'Zone3'],
        'mainEntityOfPage' => $article['url'],
        'inLanguage' => 'de',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush

@section('content')
    <a href="{{ route('articles.index', $kind) }}" class="text-[15px] text-accent">‹ {{ $section['title'] }}</a>

    <article class="mt-3">
        <p class="text-[13px] text-ink-3">{{ $article['date']->locale('de')->isoFormat('D. MMMM YYYY') }} · {{ $article['minutes'] }} min Lesezeit</p>
        <h1 class="mt-1 text-[34px] font-extrabold leading-[41px] tracking-[-0.035em]">{{ $article['title'] }}</h1>
        <p class="mt-3 text-[19px] leading-relaxed text-ink-2">{{ $article['description'] }}</p>

        @if ($article['image'])
            <img src="{{ $article['image'] }}" alt="{{ $article['image_alt'] }}" class="mt-6 aspect-[16/9] w-full rounded-card object-cover">
        @endif

        <div class="z-prose mt-6">{!! $article['html'] !!}</div>
    </article>

    <aside class="mt-12 overflow-hidden rounded-card bg-ink px-6 py-7 text-white dark:bg-surface">
        <p class="text-[13px] font-semibold text-white/60 dark:text-ink-3">Zone3 · kostenlos in der Beta</p>
        <p class="mt-2 text-[24px] font-extrabold leading-tight tracking-[-0.03em] dark:text-ink">Dein Plan für dein Ziel. Von einem Coach, der mitläuft.</p>
        <p class="mt-2 text-[15px] text-white/75 dark:text-ink-2">Zone3 baut dir einen Trainingsplan aus deinen Läufen und passt ihn an, wenn das Leben dazwischenkommt. Für Garmin, COROS und Apple Watch.</p>
        <a href="{{ route('login', ['ref' => $kind]) }}" class="mt-5 inline-flex h-12 items-center rounded-full bg-accent px-7 font-semibold text-white active:scale-[0.97]">Jetzt mitmachen</a>
    </aside>

    @if ($more->isNotEmpty())
        <h2 class="mt-12 text-[22px] font-bold">Weiterlesen</h2>
        <div class="mt-3 overflow-hidden rounded-card bg-surface">
            @foreach ($more as $i => $m)
                <a href="{{ $m['url'] }}" class="block px-5 py-3.5 active:bg-surface-2 {{ $i ? 'border-t border-line' : '' }}">
                    <span class="block text-[17px] text-ink">{{ $m['title'] }}</span>
                    <span class="block text-[13px] text-ink-3">{{ $m['minutes'] }} min</span>
                </a>
            @endforeach
        </div>
    @endif
@endsection
