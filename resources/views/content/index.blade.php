@extends('content.layout')

@section('title', $section['title'])
@section('description', $section['lead'])
@section('canonical', route('articles.index', $kind))

@section('content')
    <h1 class="text-[34px] font-extrabold leading-[41px] tracking-[-0.035em]">{{ $section['title'] }}</h1>
    <p class="mt-2 text-[17px] text-ink-2">{{ $section['lead'] }}</p>

    @if ($articles->isEmpty())
        <p class="mt-10 text-[15px] text-ink-3">Der erste Beitrag kommt bald.</p>
    @else
        <div class="mt-8 space-y-4">
            @foreach ($articles as $a)
                <a href="{{ $a['url'] }}" class="block overflow-hidden rounded-card bg-surface transition active:scale-[0.99]">
                    @if ($a['image'])
                        <img src="{{ $a['image'] }}" alt="{{ $a['image_alt'] }}" loading="lazy" class="aspect-[16/7] w-full object-cover">
                    @endif
                    <div class="px-5 py-4">
                        <p class="text-[13px] text-ink-3">{{ $a['date']->locale('de')->isoFormat('D. MMMM YYYY') }} · {{ $a['minutes'] }} min</p>
                        <h2 class="mt-1 text-[22px] font-bold leading-tight tracking-[-0.02em] text-ink">{{ $a['title'] }}</h2>
                        <p class="mt-1.5 text-[15px] text-ink-2">{{ $a['description'] }}</p>
                    </div>
                </a>
            @endforeach
        </div>
    @endif

    @foreach ($other as $key => $o)
        <a href="{{ route('articles.index', $key) }}" class="mt-10 block rounded-card bg-surface px-5 py-4 active:scale-[0.99]">
            <span class="block text-[17px] font-semibold text-ink">{{ $o['title'] }} →</span>
            <span class="block text-[15px] text-ink-3">{{ $o['lead'] }}</span>
        </a>
    @endforeach
@endsection
