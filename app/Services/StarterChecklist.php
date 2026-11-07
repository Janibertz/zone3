<?php

namespace App\Services;

use App\Models\CoachMessage;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Einsteiger-Checkliste auf „Heute“ (Jan, 07.11.2026, Muster aus dem
 * Onboarding einer anderen Lauf-App).
 *
 * Die Haken setzt Zone3 selbst aus den Daten, nie der Athlet — eine Liste,
 * die man abhaken muss, ist eine zweite Wahrheit neben den Daten. Einzige
 * Ausnahme ist „Plan ansehen“: das steht nirgends, also merkt sich die
 * Planseite den ersten Besuch (`users.starter_plan_viewed_at`).
 *
 * Fest oben auf „Heute“, für neue Athleten und Beta-Tester. Wer alles
 * erledigt hat, bekommt das Beta-Abzeichen (`users.beta_badge_at`, bleibt),
 * sieht einmal die Glückwunsch-Karte und schliesst sie.
 */
class StarterChecklist
{
    /** Reihenfolge = Anzeige. `optional` zählt nicht in den Fortschritt. */
    public const ITEMS = [
        'goal'          => ['label' => 'Dein Ziel eintragen'],
        'plan'          => ['label' => 'Deinen Plan erstellen'],
        'watch'         => ['label' => 'Eine Uhr verbinden', 'optional' => true],
        'plan_viewed'   => ['label' => 'Deinen Plan ansehen'],
        'coach_hi'      => ['label' => 'Sag deinem Coach Hallo'],
        'notify'        => ['label' => 'Benachrichtigungen einschalten'],
        'wellbeing'     => ['label' => 'Deine erste Tagesform'],
        'first_session' => ['label' => 'Deine erste Einheit erledigen'],
        'first_week'    => ['label' => 'Deine erste Woche schaffen'],
    ];

    /**
     * Die Karte für „Heute“, oder null, wenn sie geschlossen wurde.
     *
     * Vergibt beim ersten vollständigen Stand das Beta-Abzeichen. Ein Punkt,
     * der später wieder wegfällt (Uhr getrennt), nimmt es nicht zurück.
     */
    public function forUser(User $user): ?array
    {
        if ($user->starter_dismissed_at) {
            return null;
        }

        $done = $this->done($user);

        $items = [];
        foreach (self::ITEMS as $key => $item) {
            $items[] = [
                'key'      => $key,
                'label'    => $item['label'],
                'optional' => $item['optional'] ?? false,
                'done'     => $done[$key],
                'target'   => $this->target($key),
            ];
        }

        $required = array_filter($items, fn ($i) => ! $i['optional']);
        $finished = count(array_filter($required, fn ($i) => $i['done']));
        $complete = $finished === count($required);

        if ($complete && ! $user->beta_badge_at) {
            $user->forceFill(['beta_badge_at' => now()])->save();
        }

        return [
            'items'    => $items,
            'done'     => $finished,
            'total'    => count($required),
            'percent'  => (int) round($finished / max(1, count($required)) * 100),
            'complete' => $complete,
            'badge'    => $user->beta_badge_at?->toIso8601String(),
        ];
    }

    /** @return array<string, bool> */
    public function done(User $user): array
    {
        return [
            'goal'          => $user->events()->exists(),
            'plan'          => TrainingPlan::where('user_id', $user->id)->exists(),
            'watch'         => $user->stravaAccount()->exists()
                || $user->garminConnection()->exists()
                || $user->corosConnection()->exists(),
            'plan_viewed'   => (bool) $user->starter_plan_viewed_at,
            'coach_hi'      => CoachMessage::where('user_id', $user->id)->where('role', 'user')->exists(),
            'notify'        => $user->hasTelegram() || $user->pushSubscriptions()->exists(),
            'wellbeing'     => $user->wellbeingEntries()->exists(),
            'first_session' => $this->planSessions($user)->where('status', 'completed')->exists(),
            'first_week'    => $this->firstWeekDone($user),
        ];
    }

    /**
     * Die erste Planwoche ist vorbei (Mo–So der ersten geplanten Einheit)
     * und in ihr wurde mindestens eine Einheit erledigt. Nur die Zeit
     * verstreichen lassen reicht nicht.
     */
    private function firstWeekDone(User $user): bool
    {
        $first = $this->planSessions($user)->min('planned_date');
        if (! $first) {
            return false;
        }

        $start = CarbonImmutable::parse($first)->startOfWeek();
        $end   = $start->endOfWeek();
        if ($end->isFuture()) {
            return false;
        }

        return $this->planSessions($user)
            ->where('status', 'completed')
            ->whereDate('planned_date', '>=', $start->toDateString())
            ->whereDate('planned_date', '<=', $end->toDateString())
            ->exists();
    }

    /** Geplante Einheiten eines Plans — keine Ruhetage, nichts Ungeplantes. */
    private function planSessions(User $user)
    {
        return TrainingSession::where('user_id', $user->id)
            ->whereNotNull('training_plan_id')
            ->where('type', '!=', 'rest')
            ->where(fn ($q) => $q->whereNull('was_unplanned')->orWhere('was_unplanned', false));
    }

    /**
     * Wohin ein Tipp auf den Punkt führt: eine URL oder eine Aktion auf
     * „Heute“ (Coach-Chat, Tagesform), die keine eigene Seite hat.
     */
    private function target(string $key): array
    {
        return match ($key) {
            'goal'                                  => ['href' => route('events.index')],
            'plan', 'plan_viewed', 'first_session',
            'first_week'                            => ['href' => route('plan.home')],
            'watch'                                 => ['href' => route('profile.edit', ['section' => 'connections'])],
            // Telegram und Push gleichrangig (Jan) — beide im selben Sheet.
            'notify'                                => ['action' => 'notify'],
            'coach_hi'                              => ['action' => 'chat'],
            'wellbeing'                             => ['action' => 'tagesform'],
        };
    }
}
