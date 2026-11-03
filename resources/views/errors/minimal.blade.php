{{-- Ersetzt Laravels englische Standard-Fehlerseiten (404, 419, 500, 503 …).
     Bewusst ohne Vite: eine 500 kann gerade daran liegen, dass der Build fehlt. --}}
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#f2f2f7" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <title>@yield('title') · Zone3</title>
    <style>
        :root { --bg:#f2f2f7; --card:#fff; --ink:#000; --ink3:#8e8e93; --accent:#0071e3; }
        @media (prefers-color-scheme: dark) { :root { --bg:#000; --card:#1c1c1e; --ink:#fff; --ink3:#8e8e93; --accent:#0a84ff; } }
        * { box-sizing:border-box; }
        html, body { margin:0; height:100%; background:var(--bg); color:var(--ink);
            font-family: -apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", sans-serif; -webkit-text-size-adjust:100%; }
        main { min-height:100%; display:flex; align-items:center; justify-content:center;
            padding: max(24px, env(safe-area-inset-top)) 16px max(24px, env(safe-area-inset-bottom)); }
        .card { width:100%; max-width:400px; background:var(--card); border-radius:20px; padding:32px 24px; text-align:center; }
        .code { font-size:13px; font-weight:600; color:var(--ink3); letter-spacing:.02em; }
        h1 { margin:8px 0 0; font-size:28px; line-height:34px; font-weight:800; letter-spacing:-0.03em; }
        p { margin:10px 0 0; font-size:17px; line-height:1.4; color:var(--ink3); }
        a.btn { display:block; margin-top:24px; padding:14px 16px; border-radius:14px; background:var(--accent); color:#fff;
            font-size:17px; font-weight:600; text-decoration:none; -webkit-tap-highlight-color:transparent; }
        a.btn:active { opacity:.8; }
        .dot { color:var(--accent); }
        .brand { font-weight:800; font-size:20px; letter-spacing:-0.03em; margin-bottom:20px; }
    </style>
</head>
<body>
<main>
    <div class="card">
        <div class="brand">Zone3<span class="dot">.</span></div>
        <div class="code">Fehler @yield('code')</div>
        <h1>@yield('message')</h1>
        <p>@yield('hint', 'Das hätte nicht passieren sollen. Versuch es gleich noch einmal.')</p>
        <a class="btn" href="{{ url('/dashboard') }}">Zu Heute</a>
    </div>
</main>
</body>
</html>
