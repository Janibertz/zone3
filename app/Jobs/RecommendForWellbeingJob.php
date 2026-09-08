<?php

namespace App\Jobs;

use App\Models\SessionRecommendation;
use App\Models\TrainingSession;
use App\Models\WellbeingEntry;
use App\Models\User;
use App\Services\AI\SessionContentService;
use App\Services\GarminHealthSummary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RecommendForWellbeingJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 2;
    public int $timeout = 60;

    public function __construct(
        public readonly int $userId,
        public readonly int $wellbeingEntryId,
    ) {}

    public function handle(SessionContentService $sessions, GarminHealthSummary $garmin): void
    {
        $user = User::find($this->userId);
        if (! $user) return;

        $wellbeing = WellbeingEntry::find($this->wellbeingEntryId);
        if (! $wellbeing) return;

        $today = $wellbeing->date->format('Y-m-d');

        // Find today's planned (non-rest) session in the active plan
        $session = TrainingSession::where('user_id', $this->userId)
            ->whereDate('planned_date', $today)
            ->where('status', 'planned')
            ->where('type', '!=', 'rest')
            ->whereHas('trainingPlan', fn ($q) => $q->where('is_active', true))
            ->first();

        if (! $session) {
            Log::info('RecommendForWellbeingJob: no planned session for today', [
                'user_id' => $this->userId,
                'date'    => $today,
            ]);
            return;
        }

        // Die gemessenen Werte des Tages — nicht der Wochenschnitt. Fuer die
        // Frage "was mache ich heute" ist der zu traege: wer nach vier
        // Stunden Schlaf aufsteht, soll heute etwas anderes laufen und nicht
        // erst, wenn der Schnitt nachgezogen hat.
        $metrics = $garmin->forDay($this->userId, \Carbon\CarbonImmutable::parse($today));

        $adjusted = $sessions->adjustSessionForWellbeing($session->toArray(), $wellbeing, $metrics);

        if (! $adjusted) {
            Log::warning('RecommendForWellbeingJob: AI returned no result', [
                'user_id'    => $this->userId,
                'session_id' => $session->id,
            ]);
            return;
        }

        $after = array_intersect_key($adjusted, array_flip(SessionRecommendation::FIELDS));

        // Schlaegt der Coach gar nichts anderes vor, gibt es nichts zu
        // entscheiden — eine Karte mit „alles bleibt" waere nur Laerm.
        $before = collect(SessionRecommendation::FIELDS)
            ->mapWithKeys(fn ($f) => [$f => $session->{$f}])
            ->all();

        if ($this->sameInSubstance($before, $after)) {
            Log::info('Wellbeing: keine Anpassung noetig', [
                'user_id'    => $this->userId,
                'session_id' => $session->id,
            ]);

            return;
        }

        // Eine aeltere offene Empfehlung fuer dieselbe Einheit ist ueberholt.
        SessionRecommendation::where('training_session_id', $session->id)
            ->where('status', SessionRecommendation::STATUS_PENDING)
            ->update([
                'status'       => SessionRecommendation::STATUS_EXPIRED,
                'responded_at' => now(),
            ]);

        SessionRecommendation::create([
            'user_id'             => $this->userId,
            'training_session_id' => $session->id,
            'wellbeing_entry_id'  => $wellbeing->id,
            'source'              => SessionRecommendation::SOURCE_WELLBEING,
            'reason'              => mb_substr((string) ($adjusted['reason'] ?? ''), 0, 300) ?: null,
            'before'              => $before,
            'after'               => $after,
        ]);

        Log::info('Wellbeing: Empfehlung angelegt', [
            'user_id'      => $this->userId,
            'session_id'   => $session->id,
            'new_type'     => $adjusted['type'] ?? null,
            'garmin_flags' => $metrics['flags'] ?? [],
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('RecommendForWellbeingJob failed', [
            'user_id' => $this->userId,
            'error'   => $e->getMessage(),
        ]);
    }

    /**
     * Unterscheiden sich Vorschlag und Plan ueberhaupt?
     *
     * Das Modell schreibt die Beschreibung fast immer um, auch wenn es
     * inhaltlich nichts aendert. Danach zu fragen hiesse, dem Athleten
     * taeglich eine Karte hinzustellen, auf der nichts steht. Verglichen
     * werden deshalb die Groessen, die das Training ausmachen.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function sameInSubstance(array $before, array $after): bool
    {
        foreach (['type', 'intensity', 'zone'] as $field) {
            if (($after[$field] ?? null) != ($before[$field] ?? null)) {
                return false;
            }
        }

        // Zahlen mit etwas Spiel: fuenf Prozent sind Rundung, keine Ansage.
        foreach (['distance_km', 'duration_min'] as $field) {
            $old = (float) ($before[$field] ?? 0);
            $new = (float) ($after[$field] ?? 0);

            if ($old <= 0) {
                if ($new > 0) {
                    return false;
                }
                continue;
            }

            if (abs($new - $old) / $old > 0.05) {
                return false;
            }
        }

        return true;
    }
}
