<?php

namespace App\Services;

use App\Models\TrainingSession;
use Illuminate\Support\Collection;

/**
 * Welche Tage muss die Neuberechnung überhaupt anfassen?
 *
 * Bis hierher: alle. Eine Neuberechnung löschte jede geplante Einheit und
 * liess das Modell den gesamten Rest neu erfinden — auch die Tage, an denen
 * sich nichts geändert hatte. Weil ein Sprachmodell nicht deterministisch
 * ist, kam dort jedes Mal etwas anderes heraus: derselbe Donnerstag trug
 * mal 8 km locker, mal 10, mal einen anderen Titel.
 *
 * Das Gerüst dagegen IST deterministisch. Es entsteht aus Verfügbarkeit,
 * Renndistanz, Wiedereinstiegs-Stufe und der Leiter der langen Läufe — alles
 * Grössen, die sich nicht von selbst ändern. Wenn ein Tag im neuen Gerüst
 * denselben Slot trägt wie im alten und dort bereits eine Einheit steht,
 * gibt es nichts neu zu erfinden.
 *
 * Übrig bleiben die Tage, die wirklich anders werden. Nur die wandern in den
 * Prompt. Sind es keine, entfällt der Aufruf beim Modell ganz.
 */
class PlanDeltaService
{
    /**
     * Wie weit eine Einheit von ihrem Zielumfang abweichen darf, bevor der
     * Tag als geändert gilt.
     *
     * Dieselben Werte, die der Validator anlegt — und das ist keine
     * Kosmetik: Wäre der Vergleich hier strenger, würde er genau die
     * Einheiten als veraltet melden, die der Validator gerade erst
     * durchgewunken hat. Die Folge wäre eine Schleife, in der jede
     * Neuberechnung wieder das Modell ruft.
     */
    private const LONG_RUN_TOLERANCE = 0.10;
    private const RUN_TOLERANCE      = 0.30;

    /**
     * @param  array                             $skeleton  Das frisch gebaute Gerüst
     * @param  Collection<int,TrainingSession>   $existing  Die aktuell geplanten Einheiten
     *
     * @return array{keep: list<string>, stale: list<string>}
     */
    public function split(array $skeleton, Collection $existing): array
    {
        $byDate = $existing
            ->filter(fn ($s) => $s->planned_date !== null)
            ->groupBy(fn ($s) => $s->planned_date->format('Y-m-d'));

        $keep  = [];
        $stale = [];

        foreach ($skeleton['days'] ?? [] as $date => $day) {
            // Abgeschlossene und abgesagte Tage gehören dem Athleten; die
            // Neuberechnung hat dort ohnehin nichts zu suchen.
            if (! empty($day['finalized'])) {
                continue;
            }

            $sessions = $byDate->get($date);

            // Noch nichts da — der Tag muss geschrieben werden.
            if ($sessions === null || $sessions->isEmpty()) {
                $stale[] = $date;
                continue;
            }

            if ($this->matches($day, $sessions)) {
                $keep[] = $date;
            } else {
                $stale[] = $date;
            }
        }

        // Einheiten an Tagen, die das Gerüst gar nicht mehr kennt (Fenster
        // verschoben, Verfügbarkeit geändert), fallen weg — der Validator
        // verwirft sie ohnehin.
        return ['keep' => $keep, 'stale' => $stale];
    }

    /**
     * Trägt der Tag noch das, was das Gerüst dort vorsieht?
     *
     * @param  Collection<int,TrainingSession>  $sessions
     */
    private function matches(array $day, Collection $sessions): bool
    {
        $actual = $sessions->pluck('type')->sort()->values()->all();

        // Gesperrter Tag: genau ein Ruhetag.
        if (empty($day['available'])) {
            return $actual === ['rest'];
        }

        // Festgelegter Ruhetag: ebenso.
        if (! empty($day['rest'])) {
            return $actual === ['rest'];
        }

        $slots = $day['slots'] ?? [];

        if ($slots === []) {
            // Ein Tag ohne Vorgabe darf Ruhe oder eine lockere Einheit
            // tragen — beides bleibt stehen, statt neu gewürfelt zu werden.
            return true;
        }

        // Optionale Zweiteinheiten darf das Modell weglassen. Ein Tag gilt
        // deshalb als passend, wenn die Pflicht-Slots gedeckt sind und
        // nichts Fremdes danebensteht.
        $required = collect($slots)->reject(fn ($s) => ! empty($s['optional']))
            ->pluck('type')->sort()->values()->all();
        $allowed  = collect($slots)->pluck('type')->sort()->values()->all();

        if (array_diff($required, $actual) !== []) {
            return false;
        }

        if (array_diff($actual, $allowed) !== []) {
            return false;
        }

        return $this->targetsStillFit($slots, $sessions);
    }

    /**
     * Trägt jede Einheit noch den Umfang, den das Gerüst für sie vorsieht?
     *
     * Beim langen Lauf kommt die Zahl aus der Leiter und wird eng geführt.
     * Bei allen anderen ist sie ein Anteil am Wochenumfang — dort darf mehr
     * abweichen, sonst gilt ein Tag als geändert, den der Validator gerade
     * erst akzeptiert hat.
     *
     * Feste Termine bleiben aussen vor. Ihr Zielumfang ist nur ein Richtwert
     * für die Wochenbilanz; was der Laufclub tatsächlich macht, weiss der
     * Plan nicht, und der Prompt verlangt dort ausdrücklich KEINE erfundene
     * Struktur. Ohne diese Ausnahme war ein Tag mit festem Termin dauerhaft
     * „veraltet" — und damit lief bei jeder Neuberechnung wieder das Modell,
     * obwohl sich nichts geändert hatte.
     *
     * @param  Collection<int,TrainingSession>  $sessions
     */
    private function targetsStillFit(array $slots, Collection $sessions): bool
    {
        foreach ($slots as $slot) {
            $target = $slot['target_km'] ?? null;

            if (! $target || ! empty($slot['fixed'])) {
                continue;
            }

            $session = $sessions->firstWhere('type', $slot['type']);
            if (! $session) {
                return false;
            }

            // Ohne Distanz lässt sich nichts vergleichen — dann entscheidet
            // die Dauer, die immer dasteht.
            if (! $session->distance_km) {
                continue;
            }

            $tolerance = $slot['type'] === 'long_run' ? self::LONG_RUN_TOLERANCE : self::RUN_TOLERANCE;

            if (abs($session->distance_km - $target) > $target * $tolerance) {
                return false;
            }
        }

        return true;
    }
}
