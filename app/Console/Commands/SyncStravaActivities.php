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
 * Dieser Befehl holt alle fünfzehn Minuten selbst. Er ersetzt den Webhook
 * nicht — der bleibt der schnelle Weg, eine Aktivität ist damit binnen
 * Sekunden da. Er ist das Netz darunter: fällt die Zustellung aus, kommt
 * die Aktivität eben eine Viertelstunde später. Das ist der Unterschied
 * zwischen „manchmal kaputt" und „manchmal langsam".
 *
 * Angefasst wird nur, was WIRKLICH neu ist. Hat der Webhook die Aktivität
 * schon hereingeholt, passiert hier nichts: keine zweite Zuordnung, kein
 * zweites Review, keine zweite Push-Nachricht.
 *
 * Kosten: ein Listenaufruf je verbundenem Konto und Durchlauf. Bei vier
 * Konten sind das 4 je 15 Minuten (Limit 200) und 384 am Tag (Limit 2000).
 * Wer den Takt erhöht, rechnet nach: unter fünf Minuten reisst das
 * Tageslimit.
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

                // Ein Durchlauf ohne Fehler loescht einen alten Vermerk —
                // sonst bliebe „neu verbinden" stehen, nachdem der Athlet
                // genau das getan hat.
                if ($account->sync_error !== null) {
                    $account->forceFill(['sync_error' => null, 'sync_error_at' => null])->save();
                }
            } catch (\Throwable $e) {
                // Ein Konto darf die anderen nicht mitreissen.
                Log::error('Strava-Sync fehlgeschlagen', [
                    'user_id'   => $account->user_id,
                    'exception' => $e->getMessage(),
                ]);

                $account->forceFill([
                    'sync_error'    => $this->describe($e),
                    'sync_error_at' => now(),
                ])->save();

                $this->error("Konto {$account->user_id}: {$e->getMessage()}");
            }
        }

        $this->info($imported === 0
            ? 'Keine neuen Aktivitäten.'
            : "{$imported} neue Aktivität(en) importiert.");

        return self::SUCCESS;
    }


    /**
     * Der Fehler in einem Satz, den ein Mensch lesen kann.
     *
     * Der Unterschied, auf den es ankommt: 401 heisst, dass der Athlet die
     * Freigabe entzogen hat und neu verbinden muss — daran aendert kein
     * Wiederholen etwas. Alles andere ist eine Stoerung, die von selbst
     * vorbeigeht.
     */
    private function describe(\Throwable $e): string
    {
        if ($e instanceof \Illuminate\Http\Client\RequestException) {
            $status = $e->response->status();

            return match (true) {
                $status === 401 => 'Strava weist den Zugang ab (401) — der Athlet muss neu verbinden.',
                $status === 429 => 'Stravas Kontingent ist erschoepft (429).',
                $status >= 500  => "Strava antwortet mit {$status}.",
                default         => "Strava antwortet mit {$status}.",
            };
        }

        return mb_substr($e->getMessage(), 0, 200);
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
