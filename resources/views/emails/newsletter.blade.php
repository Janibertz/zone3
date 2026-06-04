<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $subject }}</title>
<style>
  body { margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
  .wrapper { width: 100%; background-color: #f1f5f9; padding: 32px 16px; box-sizing: border-box; }
  .card { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
  .header { background: linear-gradient(135deg, #6366f1, #4f46e5); padding: 28px 32px; }
  .header-logo { display: flex; align-items: center; gap: 12px; }
  .logo-badge { width: 36px; height: 36px; background: rgba(255,255,255,0.2); border-radius: 10px; display: flex; align-items: center; justify-content: center; }
  .logo-text { color: white; font-size: 13px; font-weight: 800; letter-spacing: -0.5px; }
  .logo-name { color: white; font-size: 18px; font-weight: 700; letter-spacing: -0.5px; }
  .content { padding: 32px; color: #1e293b; font-size: 15px; line-height: 1.7; }
  .content h1 { font-size: 22px; font-weight: 700; color: #0f172a; margin: 0 0 16px; }
  .content h2 { font-size: 18px; font-weight: 700; color: #0f172a; margin: 24px 0 12px; }
  .content h3 { font-size: 16px; font-weight: 600; color: #1e293b; margin: 20px 0 8px; }
  .content p { margin: 0 0 16px; }
  .content ul, .content ol { margin: 0 0 16px; padding-left: 24px; }
  .content li { margin-bottom: 6px; }
  .content a { color: #6366f1; text-decoration: none; font-weight: 500; }
  .content a:hover { text-decoration: underline; }
  .content strong { font-weight: 700; color: #0f172a; }
  .content hr { border: none; border-top: 1px solid #e2e8f0; margin: 24px 0; }
  .content blockquote { border-left: 3px solid #6366f1; margin: 16px 0; padding: 12px 16px; background: #f8f9ff; border-radius: 0 8px 8px 0; color: #374151; }
  .footer { background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px 32px; }
  .footer p { font-size: 12px; color: #94a3b8; margin: 0 0 4px; line-height: 1.5; }
  .footer a { color: #6366f1; font-size: 12px; text-decoration: none; }
  .salutation { color: #6366f1; font-weight: 600; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="card">
    <!-- Header -->
    <div class="header">
      <div class="header-logo">
        <div class="logo-badge">
          <span class="logo-text">Z3</span>
        </div>
        <span class="logo-name">Zone3</span>
      </div>
    </div>

    <!-- Content -->
    <div class="content">
      <p class="salutation">Hallo {{ $recipientName }},</p>
      {!! $htmlContent !!}
    </div>

    <!-- Footer -->
    <div class="footer">
      <p>Du erhältst diese E-Mail, weil du den Zone3-Newsletter abonniert hast.</p>
      <p>
        <a href="{{ $unsubscribeUrl }}">Newsletter abbestellen</a>
        &nbsp;·&nbsp;
        <a href="{{ config('app.url') }}/dashboard">Zur App</a>
      </p>
      <p style="margin-top:8px;">© {{ date('Y') }} Zone3 · Dein persönlicher KI-Lauftrainer</p>
    </div>
  </div>
</div>
</body>
</html>
