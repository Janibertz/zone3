<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\AI\OpenAIClient;
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

        return back()->with('error', 'Kein aktives Push-Abo für deinen Account gefunden.');
    }

    public function toggleMaintenance(Request $request)
    {
        $current = AppSetting::get('maintenance_mode', '0');
        $new     = $current === '1' ? '0' : '1';
        AppSetting::set('maintenance_mode', $new);

        $msg = $new === '1' ? 'Wartungsmodus aktiviert.' : 'Wartungsmodus deaktiviert.';
        return back()->with('success', $msg);
    }

    /**
     * Die beiden Euro-Bremsen (Jan, 22.11.2026: „lass mich das unter den
     * Einstellungen selber ändern"). Gespeichert in `app_settings`, gelten
     * sofort und vor den Werten aus der Umgebung. 0 = unbegrenzt.
     */
    public function updateBudget(Request $request)
    {
        $data = $request->validate([
            'daily_eur' => ['required', 'numeric', 'min:0', 'max:100'],
            'user_eur'  => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        AppSetting::set(OpenAIClient::SETTING_DAILY_BUDGET, (string) round((float) $data['daily_eur'], 2));
        AppSetting::set(OpenAIClient::SETTING_USER_BUDGET, (string) round((float) $data['user_eur'], 2));

        return back()->with('success', 'Kostenbremse gespeichert.');
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
                ->get('https://api.openai.com/v1/models/' . config('services.openai.model', 'gpt-5.6-sol'));
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
            'openai_model'        => config('services.openai.model',      'gpt-5.6-sol'),
            'openai_model_mid'    => config('services.openai.model_mid',  'gpt-5.6-terra'),
            'openai_model_mini'   => config('services.openai.model_mini', 'gpt-5.6-luna'),
            'openai_flex'         => (bool) config('services.openai.flex', true),
            'openai_budget_daily' => OpenAIClient::dailyBudgetEur(),
            'openai_budget_user'  => OpenAIClient::userDefaultBudgetEur(),
            'openai_key_set'      => !empty(config('services.openai.api_key')),
            'push_key_set'        => !empty(config('services.webpush.public_key')),
            'mail_mailer'         => config('mail.default', 'log'),
            'mail_host'           => config('mail.mailers.smtp.host', '–'),
            'mail_from'           => config('mail.from.address', '–'),
            'maintenance_mode'    => AppSetting::get('maintenance_mode', '0') === '1',
            'app_env'             => app()->environment(),
            'app_url'             => config('app.url'),
            'php_version'         => PHP_VERSION,
            'laravel_version'     => app()->version(),
        ];
    }
}
