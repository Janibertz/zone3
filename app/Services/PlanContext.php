<?php

namespace App\Services;

use App\Models\Event;
use Carbon\CarbonImmutable;

/**
 * Alles, was die Planerstellung braucht — in einem benannten Objekt.
 *
 * Vorher wurde das als vierzehn positionale Argumente durchgereicht, sieben
 * davon vom Typ `array`. Zwei benachbarte zu vertauschen war ein stiller
 * Fehler: PHP nimmt es an, der Plan wird falsch, und auf diesem Pfad gibt es
 * nichts, was es merkt. Benannte Eigenschaften machen das unmöglich.
 */
readonly class PlanContext
{
    public function __construct(
        public Event $event,
        public CarbonImmutable $windowFrom,
        public CarbonImmutable $windowTo,
        public ?array $profile = null,
        public array $recentActivities = [],
        public array $wellbeing = [],
        public array $sessionRatings = [],
        public ?array $weeklyAvailability = null,
        public array $availabilityOverrides = [],
        public ?array $trainingLoad = null,
        public array $pastPlanResults = [],
        public array $otherEvents = [],
        public array $finalizedSessions = [],
        public ?array $followUpGoal = null,
        public ?string $coachNotes = null,
        public ?array $comeback = null,
        public array $crossTraining = [],
        public ?array $paces = null,
        public ?array $volume = null,
        public ?array $longRuns = null,
        public ?array $skeleton = null,
        public ?string $garminText = null,
    ) {}

    /** Tage, an denen der Athlet bereits etwas abgeschlossen oder abgesagt hat. */
    public function finalizedDates(): array
    {
        return collect($this->finalizedSessions)->pluck('date')->filter()->unique()->values()->all();
    }

    /**
     * Kopie mit Gerüst und Garmin-Text. Beide entstehen erst, nachdem der
     * restliche Kontext steht — das Gerüst braucht die Verfügbarkeit.
     */
    public function with(?array $skeleton = null, ?string $garminText = null): self
    {
        return new self(
            event:                 $this->event,
            windowFrom:            $this->windowFrom,
            windowTo:              $this->windowTo,
            profile:               $this->profile,
            recentActivities:      $this->recentActivities,
            wellbeing:             $this->wellbeing,
            sessionRatings:        $this->sessionRatings,
            weeklyAvailability:    $this->weeklyAvailability,
            availabilityOverrides: $this->availabilityOverrides,
            trainingLoad:          $this->trainingLoad,
            pastPlanResults:       $this->pastPlanResults,
            otherEvents:           $this->otherEvents,
            finalizedSessions:     $this->finalizedSessions,
            followUpGoal:          $this->followUpGoal,
            coachNotes:            $this->coachNotes,
            comeback:              $this->comeback,
            crossTraining:         $this->crossTraining,
            paces:                 $this->paces,
            volume:                $this->volume,
            longRuns:              $this->longRuns,
            skeleton:              $skeleton    ?? $this->skeleton,
            garminText:            $garminText  ?? $this->garminText,
        );
    }
}
