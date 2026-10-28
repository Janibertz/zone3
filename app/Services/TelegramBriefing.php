<?php

namespace App\Services;

use App\Models\TrainingSession;
use App\Models\User;
use App\Observers\SessionRecommendationObserver;
use Carbon\CarbonImmutable;

/**
 * Was heute ansteht, als Nachricht — ohne KI-Aufruf.
 *
 * Das Morgen-Briefing und der Befehl /heute zeigen dieselbe Einheit; das
 * Briefing hängt die Garmin-Werte der Nacht und die Frage nach der
 * Tagesform an. Die Zahlen stehen schon in der Einheit und in den
 * Garmin-Werten; ein Modell, das sie umformuliert, kostet Geld und kann
 * sich verrechnen.
 */
class TelegramBriefing
{
    public function today(User $user, bool $morning = false): string
    {
        $plan = $user->activeTrainingPlan;

        $sessions = $plan
            ? TrainingSession::where('training_plan_id', $plan->id)
                ->whereDate('planned_date', now()->toDateString())
                ->where('status', '!=', 'skipped')
                ->orderBy('sort_order')
                ->get()
            : collect();

        $greeting = $morning ? 'Guten Morgen' . ($user->name ? ', ' . strtok($user->name, ' ') : '') . "!\n\n" : '';

        $text = $greeting . $this->sessions($user, $plan, $sessions);

        // Morgens kommt die Frage nach der Tagesform an jedem Tag dazu —
        // auch bei Ruhetag und Pause: gerade dann sagt sie, wann es
        // wieder losgehen kann.
        if (! $morning) {
            return $text;
        }

        $parts = [$text];

        if ($garmin = $this->garminNight($user)) {
            $parts[] = $garmin;
        }

        if (! $this->checkedInToday($user)) {
            $parts[] = 'Wie fühlst du dich heute? Trag kurz deine Tagesform in der App ein.';
        }

        return implode("\n\n", $parts);
    }

    /**
     * Der Knopf unter dem Morgen-Briefing — nur solange die Tagesform fehlt.
     * Er öffnet das Dashboard mit offenem Check-in.
     *
     * @return array<int, array<int, array{text:string, url:string}>>|null
     */
    public function morningButtons(User $user): ?array
    {
        return $this->checkedInToday($user)
            ? null
            : [[['text' => 'Tagesform eintragen', 'url' => url('/dashboard?tagesform=1')]]];
    }

    public function checkedInToday(User $user): bool
    {
        return $user->wellbeingEntries()->whereDate('date', now()->toDateString())->exists();
    }

    private function sessions(User $user, $plan, $sessions): string
    {
        $profile = $user->runnerProfile;
        if ($profile?->isPausedOn()) {
            $why = $profile->training_pause_reason ? " ({$profile->training_pause_reason})" : '';

            return '**Trainingspause bis ' . $profile->training_paused_until->format('d.m.') . "{$why}.** Erhol dich gut.";
        }

        if ($sessions->isEmpty()) {
            return $plan ? 'Heute steht nichts im Plan.' : 'Du hast noch keinen aktiven Trainingsplan.';
        }

        if ($sessions->every(fn ($s) => $s->type === 'rest')) {
            return '**Heute: Ruhetag.** Erholung ist Teil des Trainings.';
        }

        // Doppel-Schwelle: beide Läufe standen schon in der Nachricht, aber
        // zweimal als „Heute:" — welcher morgens und welcher abends gelaufen
        // wird, sagte sie nicht.
        $runs  = $sessions->filter(fn ($s) => in_array($s->type, TrainingPlanValidator::RUN_TYPES, true))->values();
        $label = function (TrainingSession $s) use ($runs): string {
            if ($runs->count() < 2 || ! $runs->contains('id', $s->id)) {
                return 'Heute';
            }

            return $runs->first()->id === $s->id ? 'Morgens' : 'Abends';
        };

        $lines = [];
        foreach ($sessions->where('type', '!=', 'rest') as $s) {
            $lines[] = '**' . $label($s) . ': ' . SessionRecommendationObserver::describe($s->only(['title', 'type', 'distance_km', 'duration_min', 'pace_target'])) . '**';

            $description = trim(strtok((string) $s->description, "\n"));
            if ($description !== '') {
                $lines[] = $description;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Die Werte der letzten Nacht, ohne KI. Was Garmin noch nicht geschickt
     * hat (Schlaf kommt erst, wenn die Uhr synchronisiert hat), fehlt einfach
     * — das Briefing wartet nicht darauf.
     *
     * Readiness steht bewusst nicht drin: sie ist aus der Oberfläche
     * genommen, und Schritte und Stress sagen morgens noch nichts.
     */
    private function garminNight(User $user): ?string
    {
        if (! $user->receivesGarminHealth()) {
            return null;
        }

        $day    = app(GarminHealthSummary::class)->forDay($user->id, CarbonImmutable::today());
        $values = $day['values'];
        $lines  = [];

        if (isset($values['hrv'])) {
            $lines[] = sprintf('HRV %d ms%s', round($values['hrv']['value']), $this->versus($values['hrv']['pct']));
        }
        if (isset($values['resting_hr'])) {
            $lines[] = sprintf('Ruhepuls %d bpm%s', $values['resting_hr']['value'], $this->versus($values['resting_hr']['pct']));
        }
        if (isset($values['sleep'])) {
            $lines[] = 'Schlaf ' . number_format($values['sleep']['value'], 1, ',', '') . ' h'
                . ($values['sleep']['score'] !== null ? " (Score {$values['sleep']['score']})" : '');
        }
        if (isset($values['body_battery'])) {
            $lines[] = "Body Battery {$values['body_battery']['value']}";
        }

        if (! $lines) {
            return null;
        }

        $text = "**Deine Nacht**\n" . implode("\n", $lines);

        if ($day['flagged']) {
            $text .= "\nDeine Werte weichen heute deutlich von deinem Normalbereich ab. Hör beim Training besonders auf dich.";
        }

        return $text;
    }

    private function versus(?float $pct): string
    {
        if ($pct === null) {
            return '';
        }

        return abs($pct) < 5 ? ' · normal' : sprintf(' · %+d %% zu deinem Schnitt', round($pct));
    }
}
