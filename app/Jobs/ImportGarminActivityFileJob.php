<?php

namespace App\Jobs;

use App\Models\Activity;
use App\Models\GarminConnection;
use App\Models\GarminWebhookEvent;
use App\Services\Fit\FitDecoder;
use App\Services\Garmin\GarminClient;
use App\Services\Strength\StrengthSets;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Die Sätze eines Krafttrainings aus der Original-FIT-Datei.
 *
 * Die JSON-Zusammenfassung der Activity API kennt keine Übungen. Die stehen
 * nur in der FIT-Datei, die Garmin über den Activity-Files-Push als
 * `callbackURL` anbietet (gültig 24 Stunden). Geladen wird nur für
 * Krafttrainings — für einen Lauf hat Zone3 alles aus `activityDetails`.
 *
 * Die Aktivität muss schon da sein (gesucht über die Garmin-ID). Kommt die
 * Datei vor der Zusammenfassung, versucht der Job es später noch einmal.
 * Kommen die Aktivitäten eines Athleten von Strava, gibt es keine Zeile mit
 * Garmin-ID, und die Sätze fallen weg — Strava wird dafür nicht extra
 * bedient (Jans Entscheidung, 06.11.2026).
 */
class ImportGarminActivityFileJob implements ShouldQueue
{
    use Queueable;

    /** Sechs Versuche im Abstand von fünf Minuten: die Zusammenfassung kommt meist in Sekunden. */
    public int $tries = 6;

    public int $timeout = 120;

    private const STRENGTH_TYPES = ['WeightTraining', 'Workout'];

    public function __construct(public readonly array $item) {}

    public function backoff(): int
    {
        return 300;
    }

    public function handle(GarminClient $client, FitDecoder $fit): void
    {
        $item         = $this->item;
        $garminUserId = (string) ($item['userId'] ?? '');
        $connection   = $garminUserId !== '' ? GarminConnection::where('garmin_user_id', $garminUserId)->first() : null;
        $activityId   = (int) ($item['activityId'] ?? (preg_match('/^(\d+)/', (string) ($item['summaryId'] ?? ''), $m) ? $m[1] : 0));

        $outcome = function (string $outcome, ?string $note = null) use ($connection, $garminUserId, $item): void {
            GarminWebhookEvent::create([
                'type'           => 'activityFiles',
                'garmin_user_id' => $garminUserId ?: null,
                'user_id'        => $connection?->user_id,
                'outcome'        => $outcome,
                'note'           => $note,
                // Ohne Token: der callbackURL trägt ihn als Parameter.
                'payload'        => json_encode(array_diff_key($item, ['callbackURL' => 1]) + ['callback_host' => parse_url((string) ($item['callbackURL'] ?? ''), PHP_URL_HOST)]),
            ]);
        };

        if (! $connection) {
            $outcome(GarminWebhookEvent::OUTCOME_UNKNOWN_USER);

            return;
        }
        if (! $connection->allows(GarminConnection::ACTIVITY)) {
            $outcome(GarminWebhookEvent::OUTCOME_NOT_PERMITTED);

            return;
        }
        if (strtoupper((string) ($item['fileType'] ?? 'FIT')) !== 'FIT' || empty($item['callbackURL'])) {
            $outcome(GarminWebhookEvent::OUTCOME_NOTHING_TO_STORE, 'keine FIT-Datei');

            return;
        }

        $activity = $this->findActivity($connection->user_id, $activityId);

        if (! $activity) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff());

                return;
            }
            $outcome(GarminWebhookEvent::OUTCOME_NOTHING_TO_STORE, 'Aktivität nicht gefunden');

            return;
        }

        if (! in_array($activity->type, self::STRENGTH_TYPES, true)) {
            $outcome(GarminWebhookEvent::OUTCOME_NOTHING_TO_STORE, 'kein Krafttraining');

            return;
        }

        try {
            $decoded = $fit->decode($client->activityFile($connection, (string) $item['callbackURL']));
        } catch (Throwable $e) {
            Log::warning('Garmin: FIT-Datei nicht geladen', ['user_id' => $connection->user_id, 'garmin_activity_id' => $activityId, 'error' => $e->getMessage()]);
            $outcome(GarminWebhookEvent::OUTCOME_CHECK_FAILED, mb_substr($e->getMessage(), 0, 250));

            return;
        }

        $sets = StrengthSets::fromFit($decoded['sets'], Activity::SOURCE_GARMIN);
        if ($sets === []) {
            $outcome(GarminWebhookEvent::OUTCOME_NOTHING_TO_STORE, 'keine Sätze in der Datei');

            return;
        }

        $activity->update(['strength_sets' => $sets]);
        $outcome(GarminWebhookEvent::OUTCOME_STORED, count($sets) . ' Sätze · ' . $activity->name);
    }

    private function findActivity(int $userId, int $activityId): ?Activity
    {
        return $activityId > 0
            ? Activity::where('user_id', $userId)->where('garmin_activity_id', $activityId)->first()
            : null;
    }
}
