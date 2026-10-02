<?php

namespace App\Services;

use App\Models\Event;
use App\Models\PlanInterview;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Das Interview vor dem naechsten Block — Vorbelegung und Wirkung.
 *
 * Zwei Methoden, und die Trennung zwischen ihnen ist der ganze Entwurf:
 *
 * `prefill()` sagt, was Zone3 schon weiss. Jede Frage zeigt die Antwort der
 * Datenbank, und der Athlet korrigiert nur. Das ist nicht nur hoeflich —
 * eine Frage, deren Antwort schon in den Daten steht, erzeugt eine zweite
 * Wahrheit daneben, und daran hat dieses Projekt mehrfach Tage verloren.
 *
 * `apply()` schreibt die Antworten dorthin, wo sie WIRKEN: ins Wochenraster,
 * in die Rennbilanz, in den Umfangsdeckel. Nur was sich nicht strukturieren
 * laesst, geht als Notiz an den Coach. Das Geruest ist bindend und liest
 * keine Freitexte — ein Interview, das nur den Prompt fuettert, waere
 * dieselbe Konstruktion, die bei den Renntagen dreimal versagt hat.
 */
class PlanInterviewService
{
    public function __construct(
        private readonly WeeklyVolumeService $volume,
    ) {}

    // ── Was die App schon weiss ──────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function prefill(User $user): array
    {
        $profile = $user->runnerProfile;

        return [
            'lastBlock'    => $this->lastBlock($user),
            'adherence'    => $this->adherence($user),
            'availability' => $this->availability($profile?->weekly_availability),
            'upcoming'     => $this->upcomingEvents($user),
            'volume'       => $this->recentVolume($user),
            'skipReasons'  => PlanInterview::SKIP_REASONS,
            'focusOptions' => PlanInterview::FOCUS,
        ];
    }

    /**
     * Der letzte Block: welches Rennen, welches Ziel, und ob die App das
     * Ergebnis schon kennt.
     *
     * Die Zeit steht meist in Strava — eine Aktivitaet am Renntag mit der
     * passenden Distanz ist der beste Vorschlag, den wir machen koennen.
     * Gefragt wird trotzdem, denn eine Trainingsaktivitaet am selben Tag
     * saehe genauso aus.
     *
     * @return array<string, mixed>|null
     */
    private function lastBlock(User $user): ?array
    {
        $event = Event::where('user_id', $user->id)
            ->whereDate('event_date', '<', now()->toDateString())
            ->whereDate('event_date', '>=', now()->subMonths(3)->toDateString())
            ->orderByDesc('event_date')
            ->first();

        if (! $event) {
            return null;
        }

        return [
            'id'            => $event->id,
            'name'          => $event->name,
            'date'          => $event->event_date->format('Y-m-d'),
            'date_label'    => $event->event_date->format('d.m.Y'),
            'distance'      => $event->distance_label,
            'target_label'  => $event->target_time_formatted,
            'target_min'    => $event->target_minutes,
            // Der Vorschlag aus Strava, falls an dem Tag etwas Passendes liegt.
            'measured_min'  => $this->measuredMinutes($user, $event),
            'days_ago'      => (int) $event->event_date->diffInDays(now()),
        ];
    }

    /**
     * Die Dauer der laengsten Laufaktivitaet am Renntag.
     *
     * Bewusst nur ein Vorschlag im Feld: Zone3 kann nicht unterscheiden, ob
     * der Lauf das Rennen war oder der Weg zur Startnummernausgabe.
     */
    private function measuredMinutes(User $user, Event $event): ?int
    {
        $activity = $user->activities()
            ->whereDate('start_date', $event->event_date->toDateString())
            ->where('type', 'Run')
            ->orderByDesc('distance')
            ->first();

        if (! $activity || ! $activity->moving_time) {
            return null;
        }

        return (int) round($activity->moving_time / 60);
    }

    /**
     * Geplant, absolviert, ausgelassen — die Zahl, die die Frage nach dem
     * Warum ueberhaupt rechtfertigt.
     *
     * @return array<string, int>
     */
    private function adherence(User $user, int $days = 90): array
    {
        $since = now()->subDays($days)->toDateString();

        $rows = TrainingSession::where('user_id', $user->id)
            ->whereDate('planned_date', '>=', $since)
            ->whereDate('planned_date', '<', now()->toDateString())
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $done    = (int) ($rows['completed'] ?? 0);
        $skipped = (int) ($rows['skipped'] ?? 0);
        $open    = (int) ($rows['planned'] ?? 0);

        return [
            'days'      => $days,
            'completed' => $done,
            'skipped'   => $skipped,
            'open'      => $open,
            'total'     => $done + $skipped + $open,
            'skip_pct'  => $done + $skipped > 0 ? (int) round($skipped / ($done + $skipped) * 100) : 0,
        ];
    }

    /**
     * Das Wochenraster als Tage und typische Dauer — so, wie das Interview
     * danach fragt.
     *
     * @param  array<string, array>|null  $weekly
     * @return array<string, mixed>
     */
    private function availability(?array $weekly): array
    {
        $open = collect($weekly ?? [])->filter(fn ($d) => (bool) ($d['available'] ?? false));

        return [
            'days'    => $open->count(),
            // Der typische Tag, nicht der laengste: der Sonntag mit 180 min
            // ist die Ausnahme, nicht die Regel.
            'minutes' => (int) round($open->median(fn ($d) => (int) ($d['duration_min'] ?? 0)) ?: 0),
            'grid'    => $weekly ?? [],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function upcomingEvents(User $user): array
    {
        return Event::where('user_id', $user->id)
            ->whereDate('event_date', '>=', now()->toDateString())
            ->orderBy('event_date')
            ->get()
            ->map(fn (Event $e) => [
                'id'         => $e->id,
                'name'       => $e->name,
                'date_label' => $e->event_date->format('d.m.Y'),
                'distance'   => $e->distance_label,
                'days_until' => $e->days_until,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function recentVolume(User $user): array
    {
        $v = $this->volume->forUser($user->id);

        return [
            'has_data' => (bool) ($v['has_data'] ?? false),
            'avg'      => $v['avg_km']        ?? null,
            'last'     => $v['last_km']       ?? null,
            'longest'  => $v['longest_run']   ?? null,
            'ceiling'  => $v['next_week_max'] ?? null,
        ];
    }

    // ── Was die Antworten bewirken ───────────────────────────────────────

    /**
     * Die Folgen schreiben — einmal, und nachvollziehbar.
     *
     * @return list<string>  Was tatsaechlich geaendert wurde, fuer die Quittung
     */
    public function apply(PlanInterview $interview): array
    {
        if ($interview->applied_at) {
            return [];
        }

        $user    = $interview->user;
        $profile = $user->runnerProfile;
        $done    = [];

        // ── 1. Die Rennbilanz, die bisher leer blieb ─────────────────────
        //
        // `pastPlanResults()` filtert auf `overall_rating` und lief deshalb
        // seit jeher ins Leere: acht vergangene Rennen, kein einziges
        // Ergebnis. Hier wird es gefuellt.
        if ($interview->reviewed_event_id && $interview->block_rating) {
            $plan = TrainingPlan::where('user_id', $user->id)
                ->where('event_id', $interview->reviewed_event_id)
                ->latest()
                ->first();

            if ($plan) {
                $plan->update(array_filter([
                    'overall_rating'      => $interview->block_rating,
                    'actual_time_hours'   => $interview->actual_minutes ? intdiv($interview->actual_minutes, 60) : null,
                    'actual_time_minutes' => $interview->actual_minutes ? $interview->actual_minutes % 60 : null,
                    'result_notes'        => $interview->block_note,
                ], fn ($v) => $v !== null));

                $done[] = 'Ergebnis des letzten Blocks festgehalten';
            }
        }

        // ── 2. Das Wochenraster — der staerkste Hebel aufs Geruest ───────
        if ($profile && $interview->days_per_week) {
            $grid = $this->rebuildGrid(
                $profile->weekly_availability ?? [],
                $interview->days_per_week,
                $interview->minutes_per_day,
            );

            $profile->update(['weekly_availability' => $grid]);

            $done[] = "Wochenraster auf {$interview->days_per_week} Tage gesetzt";
        }

        // ── 3. Was sich nicht strukturieren laesst, geht an den Coach ────
        foreach ($this->notes($interview) as $note) {
            $profile?->rememberNote($note);
        }

        if ($this->notes($interview) !== []) {
            $done[] = 'Notizen an den Coach übergeben';
        }

        $interview->update(['applied_at' => now()]);

        Log::info('Plan-Interview angewendet', [
            'user_id'      => $user->id,
            'interview_id' => $interview->id,
            'changes'      => $done,
        ]);

        return $done;
    }

    /**
     * Das Raster auf die gewuenschte Tageszahl bringen.
     *
     * Welche Tage gestrichen werden, entscheidet die bisherige Dauer: der
     * kuerzeste Tag faellt zuerst. Das erhaelt den langen Sonntag, und es
     * aendert nichts an den Tagen, die bleiben — wer von fuenf auf vier geht,
     * soll nicht sein ganzes Raster neu finden.
     *
     * @param  array<string, array>  $grid
     * @return array<string, array>
     */
    private function rebuildGrid(array $grid, int $days, ?int $minutes): array
    {
        $all = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

        foreach ($all as $day) {
            $grid[$day] ??= ['available' => false, 'duration_min' => 0];
        }

        // Die derzeit offenen Tage, laengster zuerst.
        $open = collect($all)
            ->filter(fn ($d) => (bool) ($grid[$d]['available'] ?? false))
            ->sortByDesc(fn ($d) => (int) ($grid[$d]['duration_min'] ?? 0))
            ->values();

        // Zu wenige? Die uebrigen Tage in einer festen Reihenfolge auffuellen,
        // damit zwei Laeufe nicht auf aufeinanderfolgenden Tagen landen.
        $filler = ['tuesday', 'thursday', 'sunday', 'saturday', 'wednesday', 'monday', 'friday'];

        foreach ($filler as $day) {
            if ($open->count() >= $days) {
                break;
            }
            if (! $open->contains($day)) {
                $open->push($day);
            }
        }

        $keep = $open->take($days)->all();

        foreach ($all as $day) {
            $isOpen = in_array($day, $keep, true);

            $grid[$day] = [
                'available'    => $isOpen,
                // Eine vorhandene, laengere Dauer bleibt stehen — der lange
                // Sonntag soll nicht auf den Durchschnittswert schrumpfen.
                'duration_min' => $isOpen
                    ? max((int) ($grid[$day]['duration_min'] ?? 0), (int) $minutes)
                    : 0,
            ] + array_diff_key($grid[$day], ['available' => null, 'duration_min' => null]);
        }

        return $grid;
    }

    /**
     * Die Saetze fuer den Coach — nur das, wofuer es kein Feld gibt.
     *
     * @return list<string>
     */
    private function notes(PlanInterview $interview): array
    {
        $notes = [];

        if ($interview->block_note) {
            $notes[] = 'Rückblick auf den letzten Block: ' . trim($interview->block_note);
        }

        if ($interview->skip_reason && $interview->skip_reason !== 'none') {
            $reason  = PlanInterview::SKIP_REASONS[$interview->skip_reason] ?? $interview->skip_reason;
            $notes[] = 'Einheiten fielen zuletzt vor allem aus wegen: ' . $reason
                . ($interview->skip_note ? ' — ' . trim($interview->skip_note) : '');
        }

        if ($interview->changes_note) {
            $notes[] = 'Geändert hat sich: ' . trim($interview->changes_note);
        }

        if ($interview->free_note) {
            $notes[] = trim($interview->free_note);
        }

        return $notes;
    }

    /**
     * Was der Planer aus dem Interview erfaehrt.
     *
     * Bewusst knapp und bewusst getrennt von den Coach-Notizen: hier stehen
     * nur die Groessen, mit denen das Geruest und der Prompt rechnen.
     *
     * @return array<string, mixed>|null
     */
    public function forPlan(User $user): ?array
    {
        $interview = PlanInterview::where('user_id', $user->id)
            ->whereNotNull('completed_at')
            ->latest('completed_at')
            ->first();

        // Ein Interview von vor einem halben Jahr beschreibt einen anderen
        // Athleten. Nach acht Wochen zaehlen wieder die Daten allein.
        if (! $interview || $interview->completed_at->lt(now()->subWeeks(8))) {
            return null;
        }

        return [
            'at'            => $interview->completed_at->format('Y-m-d'),
            'focus'         => $interview->focus,
            'focus_label'   => PlanInterview::FOCUS[$interview->focus] ?? null,
            'skip_reason'   => $interview->skip_reason,
            'skip_label'    => PlanInterview::SKIP_REASONS[$interview->skip_reason] ?? null,
            'volume_factor' => $interview->volumeFactor(),
            'block_rating'  => $interview->block_rating,
        ];
    }
}
