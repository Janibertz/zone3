<?php

namespace App\Console\Commands;

use App\Http\Controllers\WeekAvailabilityController;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Console\Command;

/**
 * Der Anstoß zur Wochenabfrage.
 *
 * Die Abfrage selbst gibt es seit dem Umbau der Verfügbarkeit: Sonntag und
 * Montag fragt das Dashboard, ob die kommende Woche zum Raster im Profil
 * passt. Nur sah sie niemand, der nicht ohnehin das Dashboard öffnete — und
 * wer am Sonntagabend im Urlaub sitzt, tut genau das nicht. Die Karte wartete
 * auf einen Besuch, der zu spät kam: Montag früh steht der Plan schon.
 *
 * Deshalb Sonntagabend eine Benachrichtigung, und nur an die, bei denen sie
 * etwas ändern kann.
 */
class SendWeekCheckReminders extends Command
{
    protected $signature   = 'push:week-check';
    protected $description = 'Erinnert sonntags an die Wochenabfrage — aber nur, wenn sie noch aussteht.';

    public function handle(WebPushService $webPush): int
    {
        $users = User::where('push_notifications_enabled', true)
            ->whereHas('pushSubscriptions')
            ->with(['runnerProfile', 'coach'])
            ->get();

        $sent = 0;

        foreach ($users as $user) {
            // isDue prüft Wochentag und ob die Woche schon bestätigt wurde.
            // Damit sagt der Command dasselbe wie die Karte im Dashboard —
            // eine Benachrichtigung, die auf eine bereits erledigte Abfrage
            // zeigt, wäre schlimmer als keine.
            if (! WeekAvailabilityController::isDue($user->runnerProfile)) {
                continue;
            }

            // Ohne aktiven Plan führt die Abfrage ins Leere: das Eintragen
            // von Abweichungen antwortet dann mit 422.
            if (! TrainingPlan::where('user_id', $user->id)->where('is_active', true)->exists()) {
                continue;
            }

            [$title, $body] = match ($user->coach?->specialty) {
                'motivator'  => ['Nächste Woche — passt sie? 🔥', ($user->coach->name ?? 'Dein Coach') . ' will wissen, wann du kannst. Kurz bestätigen, dann steht der Plan.'],
                'strategist' => ['Wochenplanung 📅', ($user->coach->name ?? 'Dein Coach') . ': Bevor ich die Woche rechne — stimmen deine Zeiten noch?'],
                'companion'  => ['Wie sieht deine Woche aus? 🌿', 'Urlaub, viel um die Ohren oder alles wie immer? Sag kurz Bescheid.'],
                default      => ['Passt deine Woche? 📅', 'Bestätige deine Zeiten für die kommende Woche — oder trage Abweichungen ein.'],
            };

            $webPush->sendToUser($user, $title, $body, '/dashboard');
            $sent++;
        }

        $this->info("Wochenabfrage: {$sent} von {$users->count()} erinnert.");

        return self::SUCCESS;
    }
}
