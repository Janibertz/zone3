<?php

namespace App\Jobs;

use App\Models\StravaAccount;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\BestEffortService;
use App\Services\StravaImportService;
use App\Services\StravaService;
use App\Services\WebPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Der Import einer Strava-Aktivität — hinter dem Webhook, nicht in ihm.
 *
 * Der Webhook-Handler tat das alles selbst: die Aktivität bei Strava
 * abholen (bei abgelaufenem Token zwei HTTP-Aufrufe statt einem), sie
 * speichern, der geplanten Einheit zuordnen, Bestzeiten schreiben,
 * Review-Jobs anstossen und je Abo eine Push-Nachricht verschicken —
 * ebenfalls über HTTP. Der Webserver ist `php artisan serve` und
 * standardmässig einthreadig; solange das lief, stand die Seite.
 *
 * Dazu kommt Stravas eigene Erwartung: bleibt die Antwort aus, stellt
 * Strava dasselbe Ereignis erneut zu. Ein langsamer Handler erzeugt also
 * genau die Doppelzustellung, die ihn noch langsamer macht. Jetzt
 * antwortet der Webhook sofort mit 200, und die Arbeit passiert hier.
 *
 * `ShouldBeUnique` fängt die Doppelzustellung zusätzlich ab: dieselbe
 * Aktivität desselben Kontos läuft nicht zweimal nebeneinander. Wäre sie
 * es doch, käme nichts Falsches heraus — `updateOrCreate` und die
 * `reviewed_at`-Prüfung sind wiederholbar —, aber der Athlet bekäme zwei
 * Push-Nachrichten für denselben Lauf.
 */
class ImportStravaActivityJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Strava antwortet nicht immer sofort — das Ereignis kommt, bevor die
     * Aktivität über die API abrufbar ist. Ein einzelner Versuch verlöre
     * den Lauf still.
     */
    public int $tries = 3;

    public int $timeout = 120;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $accountId,
        public readonly int $stravaActivityId,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->accountId}:{$this->stravaActivityId}";
    }

    public function handle(
        StravaService $strava,
        StravaImportService $importer,
        BestEffortService $bestEfforts,
        WebPushService $webPush,
    ): void {
        $account = StravaAccount::find($this->accountId);
        if (! $account) {
            return;
        }

        $detail = $strava->fetchActivity($account, $this->stravaActivityId);
        if (! $detail) {
            // Kein stiller Abbruch: das ist der Fall, in dem Strava die
            // Aktivität noch nicht ausliefert. Werfen heisst wiederholen.
            throw new RuntimeException(
                "Strava lieferte Aktivität {$this->stravaActivityId} nicht aus."
            );
        }

        $userId   = $account->user_id;
        $activity = $importer->importFromDetail($userId, $detail);

        // Der Athlet hat die Aktivität in Zone3 gelöscht — der Grabstein
        // in `ignored_strava_activities` hält sie draussen.
        if (! $activity) {
            return;
        }

        $isRun = $activity->type === 'Run';

        $importer->dispatchCalculationIfDue($userId, $isRun ? 1 : 0);
        $importer->matchActivityToSession($userId, $activity);
        $importer->dispatchPlanRegenerationIfNeeded($userId);

        // Ein Review für jede Einheit, die diese Aktivität abgeschlossen hat.
        TrainingSession::where('user_id', $userId)
            ->where('activity_id', $activity->id)
            ->where('status', 'completed')
            ->whereNull('reviewed_at')
            ->pluck('id')
            ->each(fn ($id) => GenerateSessionReviewJob::dispatch($id)->delay(now()->addSeconds(20)));

        if ($isRun) {
            // Die Detailantwort trägt `best_efforts` — die Aktivitätsliste nicht.
            $newRecords = $bestEfforts->syncFromActivityData($activity, $detail);
            if (! empty($newRecords)) {
                $importer->flagPendingPr($userId, $activity->id);
            }
        }

        $user = User::find($userId);
        if ($user && $user->push_notifications_enabled) {
            $distKm = $activity->distance > 0 ? round($activity->distance / 1000, 1) . ' km' : '';
            $body   = trim($activity->name . ($distKm ? " · {$distKm}" : ''));

            $webPush->sendToUser($user, 'Neue Aktivität importiert 🏃', $body, '/activities');
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Strava-Import fehlgeschlagen', [
            'account_id'  => $this->accountId,
            'strava_id'   => $this->stravaActivityId,
            'exception'   => $e->getMessage(),
        ]);
    }
}
