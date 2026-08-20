<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        {{-- maximum-scale=1 unterband das Aufziehen mit zwei Fingern. Gedacht war
             es gegen den Zoom, in den Safari beim Fokus in ein zu kleines
             Eingabefeld springt — das ist jetzt an der Ursache geloest (16px in
             .z-input). iOS ignoriert die Sperre ohnehin seit Jahren, Android
             nicht: dort konnte niemand mehr etwas vergroessern. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Theme before first paint — must run in <head>, otherwise the wrong
             theme flashes. Mirrors Composables/useDarkMode.js; keep in sync. --}}
        <script>
            (function () {
                var theme = localStorage.getItem('zone3-theme');
                if (['system', 'light', 'dark'].indexOf(theme) === -1) {
                    var legacy = localStorage.getItem('zone3-dark-mode');
                    theme = legacy === 'true' ? 'dark' : legacy === 'false' ? 'light' : 'system';
                }
                var dark = theme === 'system'
                    ? window.matchMedia('(prefers-color-scheme: dark)').matches
                    : theme === 'dark';
                document.documentElement.classList.toggle('dark', dark);
                document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
            })();
        </script>

        <!-- PWA -->
        <meta name="theme-color" content="#6366f1">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="Zone3">
        <link rel="manifest" href="/manifest.json">
        <link rel="apple-touch-icon" href="/icons/icon-192.png">

        <title inertia>{{ config('app.name', 'Zone3') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia

        <script>
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', () => {
                    navigator.serviceWorker.register('/sw.js').catch(() => {});
                });
                // When a new SW takes control (after update), reload to apply new assets
                let swRefreshing = false;
                navigator.serviceWorker.addEventListener('controllerchange', () => {
                    if (!swRefreshing) { swRefreshing = true; window.location.reload(); }
                });
            }
        </script>
    </body>
</html>
