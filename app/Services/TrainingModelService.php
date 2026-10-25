<?php

namespace App\Services;

use App\Models\RunnerProfile;

/**
 * Ob das norwegische Modell zu einem Athleten passt.
 *
 * Der Athlet wählt sein Modell selbst (Entscheidung vom 25.10.2026). Zone3
 * sperrt nichts, sagt aber, wenn die Voraussetzungen fehlen: Doppel-Schwelle
 * ist für Läufer gebaut, die seit Jahren regelmäßig laufen und genug lockeren
 * Umfang haben, um zwei Schwelleneinheiten an einem Tag zu tragen.
 *
 * Den Umfang liest Zone3 aus Strava, statt danach zu fragen — eine Frage
 * nach dem Wochenumfang wäre eine zweite Wahrheit neben den Daten.
 */
class TrainingModelService
{
    /** Darunter rät Zone3 von Norwegisch ab. Vorschlag, noch nicht von Jan bestätigt. */
    public const NORWEGIAN_MIN_YEARS = 2;

    public const NORWEGIAN_MIN_WEEKLY_KM = 40;

    public function __construct(private readonly WeeklyVolumeService $volume) {}

    /**
     * @return array{recommended: bool, reasons: list<string>, weekly_km: float|null}
     */
    public function norwegianReadiness(?RunnerProfile $profile, int $userId): array
    {
        $reasons = [];

        $years = $profile?->running_experience_years;
        if ($years !== null && $years < self::NORWEGIAN_MIN_YEARS) {
            $reasons[] = "Du läufst seit {$years} " . ($years === 1 ? 'Jahr' : 'Jahren') . '. Doppel-Schwelle ist für Läufer mit mindestens ' . self::NORWEGIAN_MIN_YEARS . ' Jahren Erfahrung gedacht.';
        }

        $volume = $this->volume->forUser($userId);
        $km     = $volume['has_data'] ? (float) $volume['median_km'] : null;

        if ($km !== null && $km < self::NORWEGIAN_MIN_WEEKLY_KM) {
            $reasons[] = 'Du läufst zurzeit rund ' . (int) round($km) . ' km pro Woche. Für zwei Schwelleneinheiten an einem Tag braucht es mindestens ' . self::NORWEGIAN_MIN_WEEKLY_KM . ' km lockeren Unterbau.';
        }

        return [
            'recommended' => $reasons === [],
            'reasons'     => $reasons,
            'weekly_km'   => $km,
        ];
    }
}
