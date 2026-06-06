<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;

/**
 * Detects whether an athlete is returning from a break (training gap) or an
 * illness/injury, and which gentle build-up step they are on. Used to show a
 * dashboard card and to shape the daily AI recommendation, even without an
 * active training plan.
 */
class ReturnToRunService
{
    private const GAP_DAYS            = 7;   // gap that counts as a break
    private const RECENT_WINDOW_DAYS  = 28;  // only surface recent comebacks
    private const TOTAL_STEPS         = 5;

    /** Build-up steps — single source of truth for the card and the recommendation. */
    public const STEPS = [
        1 => ['label' => 'Lockerer Einstieg',       'type' => 'easy_run',  'zone' => '1–2', 'max_min' => 30, 'rule' => 'Sehr locker, nur Wohlfühltempo. Kein Tempo, keine Intervalle, kein Long Run.'],
        2 => ['label' => 'Vorsichtig aufbauen',     'type' => 'easy_run',  'zone' => '2',   'max_min' => 40, 'rule' => 'Locker, etwas länger. Kein Tempo, keine Intervalle, kein Long Run.'],
        3 => ['label' => 'Umfang steigern',         'type' => 'easy_run',  'zone' => '2',   'max_min' => 50, 'rule' => 'Ruhiger Dauerlauf, optional ein paar kurze Steigerungen. Keine harten Intervalle, kein Long Run.'],
        4 => ['label' => 'Belastung erhöhen',       'type' => 'tempo_run', 'zone' => '2–3', 'max_min' => 55, 'rule' => 'Kurzer Tempoanteil erlaubt. Noch keine harten Intervalle, kein Long Run.'],
        5 => ['label' => 'Zurück im Normalbetrieb', 'type' => null,        'zone' => null,  'max_min' => null, 'rule' => 'Normale Intensität wieder möglich — du bist zurück!'],
    ];

    /**
     * @return array|null  null when not in a return-to-run phase, otherwise:
     *   ['trigger','trigger_label','step','total_steps','pre_return','current','steps']
     */
    public function statusFor(User $user): ?array
    {
        $runs = $user->activities()
            ->where('type', 'Run')
            ->where('distance', '>', 0)
            ->orderByDesc('start_date')
            ->get(['id', 'start_date'])
            ->values();

        // Need an established routine before we talk about a "return".
        if ($runs->count() < 3) {
            return null;
        }

        $today   = Carbon::now()->startOfDay();
        $lastRun = Carbon::parse($runs[0]->start_date)->startOfDay();

        // ── Illness / injury signal in the recent window ──
        $illnessDay = null;
        $illnessTrigger = null;
        $ill = $user->wellbeingEntries()
            ->where('date', '>=', $today->copy()->subDays(self::RECENT_WINDOW_DAYS)->toDateString())
            ->where(fn ($q) => $q->where('is_injured', true)->orWhere('is_sick', true))
            ->orderByDesc('date')
            ->first();
        if ($ill) {
            $illnessDay     = Carbon::parse($ill->date)->startOfDay();
            $illnessTrigger = $ill->is_injured ? 'injured' : 'sick';
        }

        $trigger = null;
        $breakAt = null;
        $returnStart = null; // null = still paused (pre-return); next run is step 1

        if ((int) abs($today->diffInDays($lastRun)) >= self::GAP_DAYS) {
            // Currently paused.
            if ($illnessDay && $illnessDay->gte($lastRun)) {
                $trigger = $illnessTrigger;
                $breakAt = $illnessDay;
            } else {
                $trigger = 'break';
                $breakAt = $lastRun;
            }
        } else {
            // Back running — find the most recent comeback (gap- or illness-based).
            $candidates = [];

            for ($i = 0; $i < $runs->count() - 1; $i++) {
                $newer = Carbon::parse($runs[$i]->start_date)->startOfDay();
                $older = Carbon::parse($runs[$i + 1]->start_date)->startOfDay();
                if ((int) abs($older->diffInDays($newer)) >= self::GAP_DAYS) {
                    $candidates[] = ['trigger' => 'break', 'breakAt' => $older, 'returnStart' => $newer];
                    break;
                }
            }

            if ($illnessDay) {
                $illReturn = null;
                for ($i = $runs->count() - 1; $i >= 0; $i--) {
                    $d = Carbon::parse($runs[$i]->start_date)->startOfDay();
                    if ($d->gte($illnessDay)) { $illReturn = $d; break; }
                }
                if ($illReturn) {
                    $candidates[] = ['trigger' => $illnessTrigger, 'breakAt' => $illnessDay, 'returnStart' => $illReturn];
                }
            }

            if (empty($candidates)) {
                return null;
            }

            usort($candidates, fn ($a, $b) => $b['returnStart']->getTimestamp() <=> $a['returnStart']->getTimestamp());
            $chosen      = $candidates[0];
            $trigger     = $chosen['trigger'];
            $breakAt     = $chosen['breakAt'];
            $returnStart = $chosen['returnStart'];

            // Only surface recent comebacks.
            if ($returnStart->lt($today->copy()->subDays(self::RECENT_WINDOW_DAYS))) {
                return null;
            }
        }

        // ── Step from runs completed since the return ──
        $runsSinceReturn = $returnStart
            ? $runs->filter(fn ($r) => Carbon::parse($r->start_date)->startOfDay()->gte($returnStart))->count()
            : 0;
        $step = $runsSinceReturn + 1;
        if ($step > self::TOTAL_STEPS) {
            return null; // build-up complete
        }

        // ── Honour a dismissal of the current phase ──
        $dismissedAt = $user->runnerProfile?->return_to_run_dismissed_at;
        if ($dismissedAt && $breakAt && $dismissedAt->copy()->startOfDay()->gte($breakAt)) {
            return null;
        }

        return [
            'trigger'       => $trigger,
            'trigger_label' => $this->triggerLabel($trigger),
            'step'          => $step,
            'total_steps'   => self::TOTAL_STEPS,
            'pre_return'    => $returnStart === null,
            'current'       => ['n' => $step] + self::STEPS[$step],
            'steps'         => array_map(fn ($n) => ['n' => $n, 'label' => self::STEPS[$n]['label']], range(1, self::TOTAL_STEPS)),
        ];
    }

    private function triggerLabel(string $trigger): string
    {
        return match ($trigger) {
            'injured' => 'Verletzung',
            'sick'    => 'Krankheit',
            default   => 'Trainingspause',
        };
    }
}
