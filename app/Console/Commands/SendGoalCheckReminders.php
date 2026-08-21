<?php

namespace App\Console\Commands;

use App\Http\Controllers\GoalCheckController;
use App\Models\User;
use App\Services\GoalCheckService;
use App\Services\WebPushService;
use Illuminate\Console\Command;

/**
 * Der Anstoß zur Zielprüfung.
 *
 * Die Karte im Dashboard sieht nur, wer das Dashboard öffnet — und wer sein
 * Ziel gerade verfehlt, öffnet es tendenziell seltener. Deshalb einmal die
 * Woche eine Nachricht, aber nur an die, bei denen es wirklich etwas zu
 * entscheiden gibt: {@see GoalCheckService} liefert für alle anderen null,
 * und dann bleibt es still.
 *
 * Der Ton folgt dem Befund, nicht dem Coach-Typ. „Du bist schneller als dein
 * Ziel" ist eine gute Nachricht und darf so klingen; „dein Umfang trägt das
 * nicht" ist keine und soll nicht mit Ausrufezeichen daherkommen.
 */
class SendGoalCheckReminders extends Command
{
    protected $signature   = 'push:goal-check';
    protected $description = 'Fragt wöchentlich, ob die Zielzeit noch zum Athleten passt — aber nur bei echter Abweichung.';

    public function handle(WebPushService $webPush, GoalCheckService $goals): int
    {
        $users = User::where('push_notifications_enabled', true)
            ->whereHas('pushSubscriptions')
            ->with(['runnerProfile', 'coach'])
            ->get();

        $sent = 0;

        foreach ($users as $user) {
            $check = GoalCheckController::current($user, $goals);

            if (! $check) {
                continue;
            }

            $coach = $user->coach?->name ?? 'Dein Coach';

            [$title, $body] = match ($check['kind']) {
                'too_conservative' => [
                    'Da geht mehr 🚀',
                    "{$coach}: Deine Daten tragen {$check['predicted']} — dein Ziel steht auf {$check['target']}. Willst du es schärfen?",
                ],
                'pace_ok_base_thin' => [
                    'Kurz über dein Ziel reden?',
                    "{$coach}: Dein Tempo trägt {$check['target']}, dein Wochenumfang noch nicht. Schau es dir an.",
                ],
                default => [
                    'Passt deine Zielzeit noch?',
                    "{$coach}: Für {$check['event_name']} steht {$check['target']}. Deine Form trägt derzeit {$check['predicted']}.",
                ],
            };

            $webPush->sendToUser($user, $title, $body, '/dashboard');
            $sent++;
        }

        $this->info("Zielprüfung: {$sent} Benachrichtigungen verschickt.");

        return self::SUCCESS;
    }
}
