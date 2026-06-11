<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rückblick {{ $periodLabel }}</title>
<style>
  body { margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
  .wrapper { width: 100%; background-color: #f1f5f9; padding: 32px 16px; box-sizing: border-box; }
  .card { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
  .header { background: linear-gradient(135deg, #6366f1, #4f46e5); padding: 28px 32px; }
  .header-logo { display: flex; align-items: center; gap: 12px; }
  .logo-badge { width: 36px; height: 36px; background: rgba(255,255,255,0.2); border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; }
  .logo-text { color: white; font-size: 13px; font-weight: 800; letter-spacing: -0.5px; }
  .logo-name { color: white; font-size: 18px; font-weight: 700; letter-spacing: -0.5px; }
  .content { padding: 32px; color: #1e293b; font-size: 15px; line-height: 1.6; }
  .content h1 { font-size: 22px; font-weight: 700; color: #0f172a; margin: 0 0 6px; }
  .sub { color: #64748b; font-size: 14px; margin: 0 0 24px; }
  .stat-table { width: 100%; border-collapse: separate; border-spacing: 8px; margin: 0 0 8px; }
  .stat { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; text-align: center; }
  .stat-big { font-size: 26px; font-weight: 800; color: #4f46e5; line-height: 1; }
  .stat-label { font-size: 12px; color: #64748b; margin-top: 6px; }
  .hl { margin: 24px 0 0; }
  .hl-row { padding: 12px 0; border-top: 1px solid #f1f5f9; display: flex; }
  .hl-emoji { font-size: 18px; width: 32px; }
  .hl-label { color: #64748b; font-size: 13px; }
  .hl-value { color: #0f172a; font-weight: 700; }
  .cta { display: inline-block; background: #4f46e5; color: #ffffff !important; text-decoration: none; font-weight: 700; font-size: 15px; padding: 13px 28px; border-radius: 12px; margin: 28px 0 4px; }
  .footer { background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px 32px; }
  .footer p { font-size: 12px; color: #94a3b8; margin: 0 0 4px; line-height: 1.5; }
  .footer a { color: #6366f1; font-size: 12px; text-decoration: none; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="card">
    <div class="header">
      <div class="header-logo">
        <span class="logo-badge"><span class="logo-text">Z3</span></span>
        <span class="logo-name">Zone3</span>
      </div>
    </div>

    <div class="content">
      <h1>Dein Rückblick: {{ $periodLabel }}</h1>
      <p class="sub">Hi {{ $recipientName }}, der Monat ist im Kasten — hier deine Fakten.</p>

      <table class="stat-table" role="presentation">
        <tr>
          <td class="stat" width="33%">
            <div class="stat-big">{{ number_format($stats['totals']['km'], 1, ',', '.') }}</div>
            <div class="stat-label">Kilometer</div>
          </td>
          <td class="stat" width="33%">
            <div class="stat-big">{{ $stats['totals']['runs'] }}</div>
            <div class="stat-label">{{ $stats['totals']['runs'] === 1 ? 'Lauf' : 'Läufe' }}</div>
          </td>
          <td class="stat" width="33%">
            <div class="stat-big">{{ number_format($stats['totals']['hours'], 1, ',', '.') }}</div>
            <div class="stat-label">Stunden</div>
          </td>
        </tr>
      </table>

      <div class="hl">
        @if(!empty($stats['longest_run']))
          <div class="hl-row">
            <span class="hl-emoji">🦵</span>
            <span><span class="hl-label">Längster Lauf:</span> <span class="hl-value">{{ $stats['longest_run']['km'] }} km</span> · {{ $stats['longest_run']['name'] }}</span>
          </div>
        @endif
        @if(!empty($stats['fastest_run']))
          <div class="hl-row">
            <span class="hl-emoji">⚡</span>
            <span><span class="hl-label">Schnellste Pace:</span> <span class="hl-value">{{ $stats['fastest_run']['pace'] }} /km</span> · {{ $stats['fastest_run']['km'] }} km</span>
          </div>
        @endif
        @if(!empty($stats['favorite_weekday']))
          <div class="hl-row">
            <span class="hl-emoji">📅</span>
            <span><span class="hl-label">Dein Lauftag:</span> <span class="hl-value">{{ $stats['favorite_weekday']['label'] }}</span> ({{ $stats['favorite_weekday']['count'] }}×)</span>
          </div>
        @endif
        @if(($stats['longest_streak'] ?? 0) > 1)
          <div class="hl-row">
            <span class="hl-emoji">📆</span>
            <span><span class="hl-label">Längste Serie:</span> <span class="hl-value">{{ $stats['longest_streak'] }} Tage</span> am Stück</span>
          </div>
        @endif
        @if(!empty($stats['prs']) && $stats['prs']['count'] > 0)
          <div class="hl-row">
            <span class="hl-emoji">🏅</span>
            <span><span class="hl-label">Neue Rekorde:</span> <span class="hl-value">{{ $stats['prs']['count'] }}</span> · {{ implode(' · ', $stats['prs']['distances']) }}</span>
          </div>
        @endif
        @if(!empty($stats['vs_previous']))
          <div class="hl-row">
            <span class="hl-emoji">{{ $stats['vs_previous']['delta_pct'] >= 0 ? '📈' : '📉' }}</span>
            <span><span class="hl-label">vs. {{ $stats['vs_previous']['prev_label'] }}:</span> <span class="hl-value">{{ $stats['vs_previous']['delta_pct'] >= 0 ? '+' : '' }}{{ $stats['vs_previous']['delta_pct'] }}%</span> km</span>
          </div>
        @endif
      </div>

      <div style="text-align:center;">
        <a href="{{ $reviewUrl }}" class="cta">Vollen Rückblick ansehen →</a>
      </div>
    </div>

    <div class="footer">
      <p>Du erhältst diese E-Mail, weil dein Monatsrückblick aktiviert ist.</p>
      <p><a href="{{ $settingsUrl }}">Monatsrückblick in den Einstellungen deaktivieren</a></p>
      <p>Zone3 · Dein KI-Lauftraining</p>
    </div>
  </div>
</div>
</body>
</html>
