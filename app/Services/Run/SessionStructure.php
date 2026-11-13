<?php

namespace App\Services\Run;

use App\Models\Event;
use App\Models\TrainingSession;
use App\Services\TrainingPaceService;

/**
 * Der Ablauf einer einzelnen Einheit außerhalb des Plan-Jobs — für alles,
 * was eine Laufeinheit ändert, ohne dass ein Gerüst daneben liegt: Coach-
 * Chat, Tagesform-Vorschlag, „härter/leichter", das Öffnen einer älteren
 * Einheit ohne Schritte und `sessions:rebuild-steps`.
 *
 * Derselbe Katalog wie im Plan ({@see RunWorkouts}), damit Liste, Details
 * und Uhr nie zwei Wahrheiten über eine Einheit haben. Ohne Gerüst fehlt
 * der `hard_min` — dann gilt der Richtwert des Typs, gedeckelt durch die
 * Dauer.
 */
class SessionStructure
{
    /** Diese Typen bekommen keinen Ablauf aus dem Katalog. */
    private const SKIP_TYPES = ['race_prep', 'yard_simulation', 'rest', 'strength', 'core', 'mobility', 'cross_training'];

    public function __construct(
        private readonly RunWorkouts $catalog,
        private readonly TrainingPaceService $paces,
    ) {}

    public function supports(TrainingSession $session): bool
    {
        return ! in_array($session->type, self::SKIP_TYPES, true)
            && empty($session->sport_type)
            && (RunWorkouts::variantsFor(['type' => $session->type]) !== []);
    }

    /**
     * Schritte, Dauer, Distanz und Pace der Einheit aus dem Katalog setzen.
     * Speichert nicht — der Aufrufer entscheidet, wann.
     *
     * @return bool  ob ein Ablauf gesetzt wurde
     */
    /**
     * @param  string|null  $easyPace  Wunsch-Pace des Athleten für die lockeren
     *   Abschnitte („6:00–6:30"), aus dem Coach-Chat. Ohne sie gelten die
     *   Tempi aus dem VDOT (Jan, 13.11.2026: „6 bis 6:30" blieb bei 4:49–5:46).
     * @param  string|null  $hardPace  Dasselbe für die harten Abschnitte
     *   (Schwelle, Intervalle, Renntempo): „die Intervalle lieber in 4:09".
     */
    public function apply(TrainingSession $session, ?string $variant = null, ?string $easyPace = null, ?string $hardPace = null): bool
    {
        if (! $this->supports($session)) {
            return false;
        }

        // Den Laufclub gibt der Trainer vor. Nur wer ausdrücklich einen
        // Baustein verlangt (Coach-Chat), bekommt dort einen Ablauf.
        if ($variant === null && $session->isFixedAppointment()) {
            return false;
        }

        $slot    = ['type' => $session->type];
        $allowed = RunWorkouts::VARIANTS[$session->type] ?? RunWorkouts::variantsFor($slot);
        // Ohne Hang keine Bergeinheit — außer der Athlet verlangt sie ausdrücklich.
        if ($variant === null && $session->user?->runnerProfile?->hills_available === false) {
            $allowed = array_values(array_diff($allowed, RunWorkouts::HILL_VARIANTS));
        }
        $variant = RunWorkouts::pick(
            $allowed,
            $variant ?? $session->workout_variant ?? RunWorkouts::guessVariant($session->type, $session->description, $allowed),
            $session->planned_date?->format('Y-m-d'),
        );

        $built = $this->catalog->build($session->type, $variant, [
            'minutes' => (int) $session->duration_min,
            'km'      => (float) $session->distance_km,
            'paces'   => self::withPaceWish($this->pacesFor($session), $easyPace, $hardPace),
        ]);

        if ($built === null) {
            return false;
        }

        $session->steps           = $built['steps'];
        $session->workout_variant = $built['workout'];
        $session->duration_min    = $built['duration_min'];
        // Ohne VDOT kennen die Abschnitte keine Pace — dann bleibt die Zahl,
        // die da war, statt einer leeren.
        $session->distance_km     = $built['distance_km'] ?? $session->distance_km;
        $session->pace_target     = $built['pace_target'] ?? $session->pace_target;
        $session->zone            = $built['zone'];
        // Zahlen stehen im Ablauf, nicht im Text — sonst widersprechen sich die beiden.
        $session->description     = SessionText::clean($session->description, $built['workout']);

        return true;
    }

    /**
     * „6-6:30", „6:00 bis 6:30" oder „6:15" → „6:00–6:30" bzw. „6:15".
     * Null, wenn sich keine plausible Pace (2:30–10:00 /km) lesen lässt.
     */
    public static function normalizePace(?string $pace): ?string
    {
        if (! $pace || ! preg_match_all('/(\d{1,2})(?::(\d{2}))?/', $pace, $mm, PREG_SET_ORDER)) {
            return null;
        }

        $secs = [];
        foreach (array_slice($mm, 0, 2) as $m) {
            $sec = (int) $m[1] * 60 + (int) ($m[2] ?? 0);
            if ($sec < 150 || $sec > 600) {
                return null;
            }
            $secs[] = $sec;
        }
        sort($secs);
        $fmt = fn (int $s) => intdiv($s, 60) . ':' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT);

        return count(array_unique($secs)) === 1 ? $fmt($secs[0]) : $fmt($secs[0]) . '–' . $fmt($secs[1]);
    }

    /** Tempi, die als „hart" gelten — eine harte Wunsch-Pace ersetzt sie alle. */
    private const HARD_PACES = ['threshold', 'sub_threshold', 'interval', 'repetition', 'marathon', 'target_pace', 'test'];

    /**
     * Die Wunsch-Pace ersetzt locker und lang bzw. alle harten Tempi. Ein
     * Baustein mit zwei harten Tempi (Over-Under, Wechseltempo) läuft dann
     * ganz in der genannten Pace — der Athlet hat genau eine genannt.
     */
    private static function withPaceWish(array $paces, ?string $easyPace, ?string $hardPace): array
    {
        if ($easy = self::normalizePace($easyPace)) {
            $paces['easy'] = $easy;
            $paces['long'] = $easy;
        }
        if ($hard = self::normalizePace($hardPace)) {
            foreach (self::HARD_PACES as $key) {
                $paces[$key] = $hard;
            }
        }

        return $paces;
    }

    /** Die Paces des Athleten, mit dem Zielrenntempo seines Rennens. */
    private function pacesFor(TrainingSession $session): array
    {
        $vdot = $session->user?->runnerProfile?->vdotInfo();
        if (! $vdot) {
            return [];
        }

        $event = $session->event_id ? Event::find($session->event_id) : null;
        $event ??= Event::where('user_id', $session->user_id)
            ->whereDate('event_date', '>=', now()->toDateString())
            ->orderBy('event_date')
            ->first();

        // Ohne Rennen gibt es kein Renntempo, die Trainingstempi gelten
        // trotzdem — sonst behielt ein Tagesvorschlag ohne Ziel die Pace,
        // die das Modell geschrieben hatte.
        $event ??= new Event();

        return $this->paces->forEvent($event, $vdot['value'], $vdot['label'] ?? null) ?? [];
    }
}
