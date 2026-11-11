<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>@yield('title') · Zone3</title>
    <meta name="description" content="@yield('description')">
    <link rel="canonical" href="@yield('canonical')">
    <meta property="og:site_name" content="Zone3">
    <meta property="og:title" content="@yield('title')">
    <meta property="og:description" content="@yield('description')">
    <meta property="og:url" content="@yield('canonical')">
    <meta property="og:type" content="@yield('og_type', 'website')">
    @hasSection('og_image')<meta property="og:image" content="@yield('og_image')">@endif
    <meta name="theme-color" content="#f2f2f7">
    {{-- Thema vor dem ersten Paint, wie in app.blade.php (useDarkMode.js). --}}
    <script>
        (function () {
            var t = null; try { t = localStorage.getItem('zone3-theme'); } catch (e) {}
            var dark = t === 'dark' || ((t === null || t === 'system') && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
            document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
            var tag = document.querySelector('meta[name="theme-color"]');
            if (tag) tag.setAttribute('content', dark ? '#000000' : '#f2f2f7');
        })();
    </script>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/favicon.ico">
    @vite('resources/js/content.js')
    @stack('head')
</head>
<body class="bg-canvas font-sans text-ink antialiased">
    <header class="sticky top-0 z-40 border-b border-line bg-canvas/80 pt-[env(safe-area-inset-top)] backdrop-blur-xl">
        <div class="mx-auto flex h-14 max-w-3xl items-center justify-between gap-4 px-4">
            <a href="/" class="shrink-0 text-ink"><svg class="h-[19px] w-auto" aria-label="Zone3." role="img" xmlns="http://www.w3.org/2000/svg" viewBox="0 -1514 6470 1544"><g transform="translate(0 0) scale(1)"><path d="M52 0H1265V-306H500C554 -366 607 -427 658 -487L1258 -1216V-1490H70V-1184H818C765 -1125 713 -1065 662 -1006L52 -274Z" fill="currentColor"/><path d="M1863.6 24C2194.6 24 2418.6 -193 2418.6 -528C2418.6 -861 2194.6 -1080 1863.6 -1080C1532.6 -1080 1307.6 -861 1307.6 -528C1307.6 -193 1532.6 24 1863.6 24ZM1863.6 -250C1742.6 -250 1656.6 -349 1656.6 -528C1656.6 -706 1743.6 -806 1863.6 -806C1983.6 -806 2070.6 -706 2070.6 -528C2070.6 -349 1983.6 -250 1863.6 -250Z" fill="currentColor"/><path d="M2849.1 -574C2849.1 -718 2919.1 -785 3028.1 -785C3137.1 -785 3200.1 -717 3200.1 -592V0H3544.1V-664C3544.1 -924 3410.1 -1079 3181.1 -1079C3022.1 -1079 2915.1 -1006 2845.1 -885V-1056H2505.1V0H2849.1Z" fill="currentColor"/><path d="M4174.7 24C4441.7 24 4654.7 -125 4693.7 -338H4384.7C4359.7 -268 4287.7 -224 4186.7 -224C4050.7 -224 3970.7 -309 3966.7 -443H4701.7V-536C4701.7 -856 4482.7 -1080 4165.7 -1080C3852.7 -1080 3630.7 -850 3630.7 -528C3630.7 -206 3850.7 24 4174.7 24ZM3968.7 -649C3983.7 -760 4058.7 -826 4174.7 -826C4289.7 -826 4365.7 -760 4380.7 -649Z" fill="currentColor"/><path d="M5334.2 24C5682.2 24 5923.2 -164 5923.2 -420C5923.2 -598 5802.2 -751 5586.2 -776V-779C5754.2 -807 5877.2 -924 5877.2 -1099C5877.2 -1342 5651.2 -1514 5334.2 -1514C4990.2 -1514 4778.2 -1310 4778.2 -1038H5115.2C5115.2 -1164 5205.2 -1248 5334.2 -1248C5458.2 -1248 5545.2 -1171 5545.2 -1059C5545.2 -950 5454.2 -884 5306.2 -884H5168.2V-632H5306.2C5464.2 -632 5567.2 -558 5567.2 -444C5567.2 -328 5471.2 -250 5331.2 -250C5180.2 -250 5089.2 -344 5089.2 -501H4749.2C4749.2 -220 4936.2 24 5334.2 24Z" fill="currentColor"/><path d="M6210.2 19C6314.2 19 6398.2 -65 6398.2 -170C6398.2 -274 6314.2 -358 6210.2 -358C6106.2 -358 6022.2 -274 6022.2 -170C6022.2 -65 6106.2 19 6210.2 19Z" class="fill-accent"/></g></svg></a>
            <nav class="flex items-center gap-4 text-[15px]">
                <a href="{{ route('articles.index', 'ratgeber') }}" class="{{ ($kind ?? null) === 'ratgeber' ? 'font-semibold text-ink' : 'text-ink-3' }}">Ratgeber</a>
                <a href="{{ route('articles.index', 'blog') }}" class="{{ ($kind ?? null) === 'blog' ? 'font-semibold text-ink' : 'text-ink-3' }}">Neu</a>
                <a href="{{ route('login', ['ref' => $kind ?? 'ratgeber']) }}" class="inline-flex h-9 items-center rounded-full bg-accent px-4 font-semibold text-white active:scale-[0.97]">Mitmachen</a>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-3xl px-4 pb-16 pt-6">
        @yield('content')
    </main>

    <footer class="border-t border-line px-4 pt-8 pb-[calc(2rem+env(safe-area-inset-bottom))]">
        <div class="mx-auto flex max-w-3xl flex-col items-center justify-between gap-3 text-[13px] text-ink-3 sm:flex-row">
            <span>Zone3 · Der Coach, der mitläuft.</span>
            <div class="flex gap-5">
                <a href="{{ route('privacy') }}">Datenschutz</a>
                <a href="{{ route('impressum') }}">Impressum</a>
                <a href="{{ route('login') }}">Anmelden</a>
            </div>
        </div>
    </footer>
</body>
</html>
