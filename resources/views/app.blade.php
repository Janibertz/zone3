<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        {{-- Zoom bleibt erlaubt. Hier stand `maximum-scale=1, user-scalable=no`
             mit der Begruendung „Zone3 ist eine App, keine Webseite" — und mit
             dem Zusatz, iOS ignoriere es ohnehin. Der Zusatz stimmt: iOS Safari
             missachtet beides seit iOS 10 absichtlich, damit Pinch-Zoom als
             Bedienhilfe erhalten bleibt. Android Chrome haelt sich daran.
             Unterm Strich hatte die Zeile auf der Hauptplattform keine Wirkung
             und auf der anderen nur die, jemandem das Vergroessern zu verbieten.

             Die Ursache des Zoom-Sprungs ist unabhaengig davon behoben: Felder
             haben unter 640px 16px (.z-input und die Regel in app.css).

             `interactive-widget=resizes-content` laesst die Android-Tastatur den
             Layout-Viewport verkleinern, so wie iOS es ohnehin tut — sonst
             liegt ein unten angeheftetes Eingabefeld hinter der Tastatur. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <!-- PWA -->
        {{-- Die Statusleiste nimmt ihre Farbe von hier, und sie muss die Farbe
             GANZ OBEN auf der Seite treffen — nicht die Markenfarbe. Hier
             stand das Indigo #6366f1, waehrend die Seite #f9fafb bzw. #09090e
             ist. `useDarkMode.js::apply()` korrigierte das, aber erst wenn Vue
             laeuft: beim Start vom Homescreen ist genau der erste Frame der,
             in dem man die Statusleiste ansieht, und der war indigo.

             Eine Angabe, nicht zwei nach `prefers-color-scheme`: Zone3 schaltet
             per Klasse auf <html>, eine Media-Angabe wuerde also die manuelle
             Wahl ignorieren. Den richtigen Wert setzt das Theme-Skript unten
             noch vor dem ersten Paint; `useDarkMode.js` haelt ihn danach
             synchron. Die drei Stellen muessen dieselben Farben benutzen. --}}
        <meta name="theme-color" content="#f9fafb">

        {{-- Theme vor dem ersten Paint — muss im <head> laufen, sonst blitzt
             das falsche Thema auf. Steht bewusst HINTER dem theme-color-Tag:
             das Skript setzt es synchron mit, und ein Tag, das noch nicht
             geparst ist, findet `querySelector` nicht.
             Spiegelt Composables/useDarkMode.js — synchron halten. --}}
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

                var tag = document.querySelector('meta[name="theme-color"]');
                if (tag) tag.setAttribute('content', dark ? '#09090e' : '#f9fafb');
            })();
        </script>
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
