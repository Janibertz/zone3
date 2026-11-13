<?php

namespace App\Services\Plan;

use App\Models\Event;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\FatigueCheck;
use Carbon\CarbonImmutable;

/**
 * Erholung nach einem Rennen (Idee 4, Jans Go vom 13.11.2026).
 *
 * Bis hierher lief der Plan am Tag nach dem Rennen einfach weiter: nach
 * einem Halbmarathon am Sonntag konnte am Dienstag die Schwelle stehen. Jetzt
 * legt das Gerüst die Tage danach fest, nicht das Modell:
 *
 *   · Länge nach Daniels' Faustregel, etwa ein lockerer Tag je 3 km Rennen:
 *     5 km 2 Tage, 10 km 3, Halbmarathon 7, Marathon 14 (höchstens 21).
 *   · Der erste Tag ist immer Ruhetag, ab dem Halbmarathon die ersten zwei.
 *   · Bis zur Hälfte nur kurze lockere Läufe (`short`), danach wieder normale
 *     Länge, aber weiter locker (`easy`). Harte Einheiten, Renntempo und ein
 *     fester harter Termin fallen in der ganzen Zeit weg.
 *   · C-Rennen (Trainingswettkampf) halbe Dauer, ein Ruhetag.
 *   · Der 5-km-Test im Plan ohne Rennen zählt wie ein 5-km-Rennen.
 *   · Schlagen in der Erholung zwei von vier Ermüdungssignalen an
 *     ({@see FatigueCheck}), hängt sie bis zu drei lockere Tage an.
 *     Verkürzt wird nie.
 *
 * Auch ein Rennen VOR dem Fenster zählt — nach dem Zielrennen beginnt ein
 * neuer Plan mit dem Rest der Erholung, und `plan:write-week` am Sonntag
 * sieht den Halbmarathon vom selben Morgen.
 */
class RaceRecovery
{
    public const STATE_REST  = 'rest';
    public const STATE_SHORT = 'short';
    public const STATE_EASY  = 'easy';

    /** Daniels: rund ein lockerer Tag je drei Kilometer Rennen. */
    private const KM_PER_DAY = 3.0;

    private const MIN_DAYS = 2;
    private const MAX_DAYS = 21;

    /** Ab dieser Distanz sind die ersten zwei Tage Ruhetage. */
    private const TWO_REST_DAYS_FROM_KM = 21.0;

    /** So viele lockere Tage hängt die Ermüdung höchstens an. */
    public const FATIGUE_EXTENSION_DAYS = 3;

    /** Höchstdauer eines Laufs in der kurzen Hälfte. */
    public const SHORT_MAX_MIN = 30;

    public function __construct(private readonly FatigueCheck $fatigue) {}

    /**
     * Wie lange nach einem Rennen?
     *
     * @return array{days: int, rest_days: int}
     */
    public static function lengthFor(?float $km, ?string $priority = null): array
    {
        $km   = max(0.0, (float) $km);
        $days = (int) min(self::MAX_DAYS, max(self::MIN_DAYS, round($km / self::KM_PER_DAY)));
        $rest = $km >= self::TWO_REST_DAYS_FROM_KM ? 2 : 1;

        if ($priority === 'C') {
            $days = max(1, (int) round($days / 2));
            $rest = 1;
        }

        return ['days' => $days, 'rest_days' => min($rest, $days)];
    }

    /**
     * Der Zustand eines Tages, `$day` Tage nach dem Rennen (1 = Tag danach).
     */
    public static function stateOn(int $day, int $days, int $restDays): ?string
    {
        if ($day < 1 || $day > $days) {
            return null;
        }
        if ($day <= $restDays) {
            return self::STATE_REST;
        }

        return $day <= (int) ceil($days / 2) ? self::STATE_SHORT : self::STATE_EASY;
    }

    /**
     * Erholungstage zwischen `$from` und `$to`, aus allen Rennen, deren
     * Erholung in diesen Zeitraum reicht.
     *
     * @return array<string, array{state: string, race: string, day: int, of: int, extended?: bool}>
     */
    public function forWindow(User $user, CarbonImmutable $from, CarbonImmutable $to, ?CarbonImmutable $today = null): array
    {
        $today = $today ?? CarbonImmutable::today();

        return $this->spread(
            $this->racesAround($user, $from->subDays(self::MAX_DAYS + self::FATIGUE_EXTENSION_DAYS), $to),
            $from,
            $to,
            $this->fatigue->isFatigued($user, $today),
            $today,
        );
    }

    /** Der Erholungszustand eines einzelnen Tages, sonst null. */
    public function on(User $user, CarbonImmutable $day): ?array
    {
        return $this->forWindow($user, $day, $day, $day)[$day->toDateString()] ?? null;
    }

    /**
     * Die Rennen legen ihre Erholung über die Tage. Der strengere Zustand
     * gewinnt (Ruhetag vor kurz vor locker), ein Renntag ist nie Erholung.
     *
     * @param  list<array{date: string, name: string, km: ?float, priority: ?string}>  $races
     */
    public function spread(array $races, CarbonImmutable $from, CarbonImmutable $to, bool $fatigued = false, ?CarbonImmutable $today = null): array
    {
        $today     = $today ?? CarbonImmutable::today();
        $raceDates = array_flip(array_column($races, 'date'));
        $rank      = [self::STATE_EASY => 1, self::STATE_SHORT => 2, self::STATE_REST => 3];
        $out       = [];

        foreach ($races as $race) {
            $raceDay = CarbonImmutable::parse($race['date'])->startOfDay();
            $len     = self::lengthFor($race['km'], $race['priority'] ?? null);
            $days    = $len['days'];

            // Ermüdung verlängert nur eine Erholung, die gerade läuft —
            // über ein Rennen in der Zukunft sagen die Werte von heute nichts.
            $extended = $fatigued
                && $raceDay->lt($today)
                && $today->lte($raceDay->addDays($days + self::FATIGUE_EXTENSION_DAYS));

            $total = $days + ($extended ? self::FATIGUE_EXTENSION_DAYS : 0);

            for ($d = 1; $d <= $total; $d++) {
                $date = $raceDay->addDays($d);
                if ($date->lt($from) || $date->gt($to)) {
                    continue;
                }
                $key = $date->toDateString();
                if (isset($raceDates[$key])) {
                    continue;
                }

                $state = $d > $days ? self::STATE_EASY : self::stateOn($d, $days, $len['rest_days']);

                if (isset($out[$key]) && $rank[$out[$key]['state']] >= $rank[$state]) {
                    continue;
                }

                $out[$key] = array_filter([
                    'state'    => $state,
                    'race'     => $race['name'],
                    'day'      => $d,
                    'of'       => $total,
                    'extended' => $d > $days ?: null,
                ], fn ($v) => $v !== null);
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * Rennen im Zeitraum: eingetragene Rennen (ohne offene Ziele) und
     * gelaufene oder geplante Renntage aus dem Plan. Letzteres fängt den
     * 5-km-Test ein, dessen Datum nach dem Blockwechsel nicht mehr am Ziel
     * steht, und ein Rennen, dessen Event inzwischen gelöscht ist.
     *
     * @return list<array{date: string, name: string, km: ?float, priority: ?string}>
     */
    public function racesAround(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $races = [];

        Event::where('user_id', $user->id)
            ->whereDate('event_date', '>=', $from->toDateString())
            ->whereDate('event_date', '<=', $to->toDateString())
            ->get()
            // Ein offenes Ziel hat nur dann einen Renntag, wenn am Blockende
            // der Test steht — und nur, solange das Datum noch das Blockende ist.
            ->filter(fn (Event $e) => ! $e->isOpen() || $e->hasOpenTest())
            ->each(function (Event $e) use (&$races) {
                $races[$e->event_date->toDateString()] = [
                    'date'     => $e->event_date->toDateString(),
                    'name'     => $e->isOpen() ? '5-km-Test' : $e->name,
                    'km'       => $e->race_km,
                    'priority' => $e->isOpen() ? 'A' : $e->priority,
                ];
            });

        TrainingSession::where('user_id', $user->id)
            ->where('type', 'race_prep')
            ->where('status', '!=', 'skipped')
            ->whereDate('planned_date', '>=', $from->toDateString())
            ->whereDate('planned_date', '<=', $to->toDateString())
            ->with('event:id,priority,goal_type')
            ->get(['id', 'event_id', 'planned_date', 'title', 'distance_km', 'status'])
            ->each(function (TrainingSession $s) use (&$races) {
                $key = $s->planned_date->toDateString();
                if (isset($races[$key])) {
                    return;
                }
                $races[$key] = [
                    'date'     => $key,
                    'name'     => $s->title ?: 'Rennen',
                    'km'       => $s->distance_km,
                    'priority' => $s->event && ! $s->event->isOpen() ? $s->event->priority : 'A',
                ];
            });

        ksort($races);

        return array_values($races);
    }
}
