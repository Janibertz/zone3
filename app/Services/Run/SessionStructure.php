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
    public function apply(TrainingSession $session, ?string $variant = null): bool
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
            'paces'   => $this->pacesFor($session),
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

        if (! $event) {
            return [];
        }

        return $this->paces->forEvent($event, $vdot['value'], $vdot['label'] ?? null) ?? [];
    }
}
