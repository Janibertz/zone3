@extends('content.layout')

@section('title', 'Häufige Fragen')
@section('description', 'Was Zone3 ist, was es kostet, welche Uhren funktionieren und was mit deinen Daten passiert.')
@section('canonical', route('faq'))

@push('head')
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush

@section('content')
    <h1 class="text-[34px] font-extrabold leading-[41px] tracking-[-0.035em]">Häufige Fragen</h1>
    <p class="mt-3 text-[19px] leading-relaxed text-ink-2">Was Zone3 ist, was es kostet, welche Uhren funktionieren und was mit deinen Daten passiert.</p>

    <nav class="mt-5 flex flex-wrap gap-2" aria-label="Themen">
        @foreach ($groups as $group)
            <a href="#{{ $group['id'] }}" class="inline-flex h-9 items-center rounded-full bg-surface px-4 text-[15px] text-ink active:bg-surface-2">{{ $group['title'] }}</a>
        @endforeach
    </nav>

    @foreach ($groups as $group)
        <section id="{{ $group['id'] }}" class="scroll-mt-20">
            <h2 class="mt-10 text-[22px] font-bold">{{ $group['title'] }}</h2>
            <div class="mt-3 overflow-hidden rounded-card bg-surface">
                @foreach ($group['items'] as $i => $item)
                    <details class="group {{ $i ? 'border-t border-line' : '' }}">
                        <summary class="flex min-h-[52px] cursor-pointer list-none items-center gap-3 px-5 py-3 text-[17px] font-semibold text-ink active:bg-surface-2 [&::-webkit-details-marker]:hidden">
                            <span class="flex-1">{{ $item['q'] }}</span>
                            <svg class="h-4 w-4 shrink-0 text-ink-3 transition-transform duration-200 ease-in-out-strong group-open:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                        </summary>
                        <div class="faq-answer px-5 pb-4 text-[17px] leading-relaxed text-ink-2">{!! $item['a'] !!}</div>
                    </details>
                @endforeach
            </div>
        </section>
    @endforeach

    <p class="mt-10 text-[15px] leading-relaxed text-ink-3">
        Deine Frage ist nicht dabei? Schreib uns über das Kontaktformular im <a href="{{ route('impressum') }}" class="text-accent">Impressum</a>.
        Alles zum Datenschutz im Detail steht in der <a href="{{ route('privacy') }}" class="text-accent">Datenschutzerklärung</a>.
    </p>

    <aside class="mt-12 overflow-hidden rounded-card bg-ink px-6 py-7 text-white dark:bg-surface">
        <p class="text-[13px] font-semibold text-white/60 dark:text-ink-3">Zone3 · gerade kostenlos</p>
        <p class="mt-2 text-[24px] font-extrabold leading-tight tracking-[-0.03em] dark:text-ink">Dein Plan für dein Ziel. Von einem Coach, der mitläuft.</p>
        <a href="{{ route('login', ['ref' => 'faq']) }}" class="mt-5 inline-flex h-12 items-center rounded-full bg-accent px-7 font-semibold text-white active:scale-[0.97]">Jetzt mitmachen</a>
    </aside>
@endsection
