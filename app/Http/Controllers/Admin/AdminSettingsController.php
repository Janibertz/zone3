<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\WebPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

class AdminSettingsController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Settings/Index', [
            'config'       => $this->systemConfig(),
            'openaiStatus' => $this->pingOpenAI(),
        ]);
    }

    public function sendTestPush(Request $request, WebPushService $push)
    {
        $user = $request->user();

        if ($user->pushSubscriptions()->exists()) {
            $push->sendTest($user);
            return back()->with('success', 'Test-Push wurde gesendet.');
        }

        return back()->with('error', 'Kein aktives Push-Abo für deinen Account gefunden. Bitte Push-Benachrichtigungen in der App aktivieren.');
    }

    private function pingOpenAI(): array
    {
        $key = config('services.openai.api_key');
        if (!$key) {
            return ['ok' => false, 'ms' => null, 'error' => 'API-Key nicht konfiguriert'];
        }

        try {
            $start    = microtime(true);
            $response = Http::withToken($key)
                ->timeout(8)
                ->get('https://api.openai.com/v1/models/' . config('services.openai.model', 'gpt-4o'));
            $ms = (int) round((microtime(true) - $start) * 1000);

            return [
                'ok'     => $response->successful(),
                'ms'     => $ms,
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            return ['ok' => false, 'ms' => null, 'error' => $e->getMessage()];
        }
    }

    private function systemConfig(): array
    {
        return [
            'openai_model'      => config('services.openai.model',      'gpt-5.5-2026-04-23'),
            'openai_model_mini' => config('services.openai.model_mini', 'gpt-5.4-mini'),
            'openai_key_set'    => !empty(config('services.openai.api_key')),
            'push_key_set'      => !empty(config('services.webpush.public_key')),
            'mail_mailer'       => config('mail.default', 'log'),
            'mail_host'         => config('mail.mailers.smtp.host', '–'),
            'mail_from'         => config('mail.from.address', '–'),
            'app_env'           => app()->environment(),
            'app_url'           => config('app.url'),
            'php_version'       => PHP_VERSION,
            'laravel_version'   => app()->version(),
        ];
    }
}
