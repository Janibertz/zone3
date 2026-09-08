<?php

namespace App\Console\Commands;

use App\Jobs\GenerateSessionReviewJob;
use App\Models\StravaAccount;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\BestEffortService;
use App\Services\StravaImportService;
use App\Services\StravaService;
use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Aktivitäten regelmässig abholen — unabhängig vom Webhook.
 *
 * Der Webhook war der EINZIGE automatische Weg, auf dem eine Aktivität in
 * Zone3 kam. Ein Kernfeature hing damit an einem Punkt, den wir nicht
 * kontrollieren: Strava muss zustellen, und wenn es das nicht tut, merkt
 * es niemand. Genau das ist passiert — sechs Tage lang kam nichts an,
 * während die Subscription gültig war, der Endpunkt in 0,35 s mit 200
 * antwortete und der Athlet lief.
 *
 * Dieser Befehl holt alle zehn Minuten selbst. Er ersetzt den Webhook
 * nicht — der bleibt der schnelle Weg, eine Aktivität ist damit binnen
 * Sekunden da. Er ist das Netz darunter: fällt die Zustellung aus, kommt
 * die Aktivität eben zehn Minuten später. Das ist der Unterschied
 * zwischen „manchmal kaputt" und „manchmal langsam".
 *
 * Angefasst wird nur, was WIRKLICH neu ist. Hat der Webhook die Aktivität
 * schon hereingeholt, passiert hier nichts: keine zweite Zuordnung, kein
 * zweites Review, keine zweite Push-Nachricht.
 *
 * Kosten: ein API-Aufruf je verbundenem Konto und Durchlauf. Bei vier
 * Konten sind das 24 Aufrufe pro Stunde — Stravas Kontingent liegt bei
 * 200 je 15 Minuten.
 */
class SyncStravaActivities extends Command
{
    protected $signature = 'strava:sync
                            {--user= : Nur dieses Konto abgleichen}
                            {--pages=1 : Wie viele Seiten à 30 Aktivitäten}';

    protected $description = 'Holt neue Strava-Aktivitäten für alle verbundenen Konten';

    public function handle(
        StravaService $strava,
        StravaImportService $importer,
        BestEffortService $bestEfforts,
        WebPushService $webPush,
    ): int {
        $accounts = StravaAccount::query()
            ->when($this->option('user'), fn ($q) => $q->where('user_id', (int) $this->option('user')))
            ->get();

        $imported = 0;

        foreach ($accounts as $account) {
            // Ohne Refresh-Token kommt niemand mehr an einen Zugang. Das
            // stillschweigend zu uebergehen waere falsch — es steht auf
            // /admin/system, aber hier gehoert es ins Log.
            if (blank($account->refresh_token)) {
                Log::warning('Strava-Sync: Konto ohne Refresh-Token', ['user_id' => $account->user_id]);
                continue;
            }

            try {
                $imported += $this->syncAccount($account, $strava, $importer, $bestEfforts, $webPush);
            } catch (\Throwable $e) {
                // Ein Konto darf die anderen nicht mitreissen.
                Log::error('Strava-Sync fehlgeschlagen', [
                    'user_id'   => $account->user_id,
                    'exception' => $e->getMessage(),
                ]);

                $this->error("Konto {$account->user_id}: {$e->getMessage()}");
            }
        }

        $this->info($imported === 0
            ? 'Keine neuen Aktivitäten.'
            : "{$imported} neue Aktivität(en) importiert.");

        return self::SUCCESS;
    }

    private function syncAccount(
        StravaAccount $account,
        StravaService $strava,
        StravaImportService $importer,
        BestEffortService $bestEfforts,
        WebPushService $webPush,
    ): int {
        $userId   = $account->user_id;
        $imported = 0;

        $list = $strava->fetchRecentActivities($account, 30 * max(1, (int) $this->option('pages')));

        foreach ($list as $summary) {
            $stravaId = $summary['id'] ?? null;
            if (! $stravaId) {
                continue;
            }

            // Schon da? Dann war der Webhook schneller — und dann ist hier
            // nichts zu tun. Kein zweites Review, keine zweite Push.
            $known = \App\Models\Activity::where('user_id', $userId)
                ->where('strava_id', $stravaId)
                ->exists();

            if ($known) {
                continue;
            }

            // Die Detailansicht, nicht der Listeneintrag: `best_efforts`
            // und `laps` stehen nur dort.
            $detail = $strava->fetchActivity($account, (int) $stravaId);
            if (! $detail) {
                continue;
            }

            $activity = $importer->importFromDetail($userId, $detail);

            // Grabstein — der Athlet hat sie in Zone3 geloescht.
            if (! $activity) {
                continue;
            }

            $imported++;

            $isRun = $activity->type === 'Run';

            $importer->dispatchCalculationIfDue($userId, $isRun ? 1 : 0);
            $importer->matchActivityToSession($userId, $activity);

            TrainingSession::where('user_id', $userId)
                ->where('activity_id', $activity->id)
                ->where('status', 'completed')
                ->whereNull('reviewed_at')
                ->pluck('id')
                ->each(fn ($id) => GenerateSessionReviewJob::dispatch($id)->delay(now()->addSeconds(20)));

            if ($isRun) {
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

            Log::info('Strava-Aktivitaet ueber den Abgleich importiert', [
                'user_id'   => $userId,
                'strava_id' => $activity->strava_id,
                'name'      => $activity->name,
            ]);

            $this->line("  {$activity->name} ({$activity->type})");
        }

        // Erst nach dem Import: die Neuberechnung sieht dann alles Neue.
        if ($imported > 0) {
            $importer->dispatchPlanRegenerationIfNeeded($userId);
        }

        return $imported;
    }
}
