<?php

namespace App\Services;

use App\Models\Event;
use Carbon\CarbonImmutable;

/**
 * Die Leiter der langen Läufe — rückwärts vom Renntag gerechnet.
 *
 * Bisher entstand der lange Lauf Woche für Woche neu: das Gerüst legte
 * einen Slot auf den Tag mit dem größten Zeitbudget, und das Modell füllte
 * eine Distanz ein. Als Bremse gab es eine einzige Regel — höchstens zwei
 * Kilometer mehr als beim letzten Mal. Für einen Marathon ist das die
 * falsche Frage. Nicht „wie viel mehr als letzte Woche", sondern „wo muss
 * der Athlet am Renntag stehen, und welche Läufe führen ihn dorthin".
 *
 * Also andersherum: Der längste Lauf steht drei Wochen vor dem Marathon.
 * Von dort führt die Leiter rückwärts bis zu dem, was der Athlet heute
 * kann, mit einer Entlastung in jeder dritten Woche. Reicht die Zeit für
 * die volle Höhe nicht, wird nicht heimlich gestreckt — dann sagt der Plan,
 * wie weit es reicht, und der Coach sagt es dem Athleten.
 */
class LongRunPlanService
{
    /**
     * Der längste Lauf je Renndistanz und wie viele Wochen vorher er liegt.
     *
     * Der Marathon-Peak steht bewusst bei 32 km und nicht bei der vollen
     * Distanz: darüber wächst die Erholungszeit schneller als der Nutzen.
     */
    private const PEAK = [
        'marathon'      => ['km' => 32.0, 'max_min' => 180, 'taper_days' => 21, 'race_pace' => true],
        'half_marathon' => ['km' => 20.0, 'max_min' => 135, 'taper_days' => 14, 'race_pace' => true],
        '10km'          => ['km' => 16.0, 'max_min' => 110, 'taper_days' => 10, 'race_pace' => false],
        '5km'           => ['km' => 13.0, 'max_min' => 90,  'taper_days' => 7,  'race_pace' => false],
    ];

    /** Wie der lange Lauf nach dem Peak zurückgeht. */
    private const TAPER_FACTORS = [0.75, 0.5, 0.35];

    /** Darunter lohnt sich ein Renntempo-Abschnitt nicht. */
    private const MIN_RACE_PACE_KM = 3.0;

    /** So viel darf ein langer Lauf gegenüber dem vorigen zulegen. */
    private const STEP_PCT = 0.15;
    private const STEP_MIN_KM = 2.0;

    /** Darüber ist die Steigerung sportlich, aber noch vertretbar. */
    private const SAFE_STEP_KM = 3.5;

    /** Entlastung in jeder dritten Woche. */
    private const CUTBACK_EVERY = 3;
    private const CUTBACK_FACTOR = 0.7;

    /**
     * @param  array       $volume     Ergebnis von {@see WeeklyVolumeService::forUser()}
     * @param  int|null    $longSec    Sekunden je Kilometer im langen Lauf
     * @param  int|null    $budgetMin  Größtes Tagesbudget der Woche in Minuten
     *
     * @return array{
     *     weeks: array<string, array{km: float, min: int, kind: string, mp_km: float}>,
     *     peak_km: float, ideal_peak_km: float, verdict: string, reachable: bool
     * }|null
     */
    public function forEvent(
        Event $event,
        array $volume,
        ?int $longSec = null,
        ?int $budgetMin = null,
        ?CarbonImmutable $today = null,
    ): ?array {
        $spec = self::PEAK[$event->race_distance] ?? null;
        if (! $spec || $event->days_until < 0) {
            return null;
        }

        $today   = $today ?? CarbonImmutable::today();
        $longSec = $longSec > 0 ? $longSec : 330; // 5:30/km, wenn keine Pace bekannt ist

        // Der Peak ist gedeckelt: durch die Zeit, die der Athlet an seinem
        // längsten Tag hat, und durch die Dauer, ab der ein langer Lauf mehr
        // kostet als er bringt.
        $minutesCap = min($spec['max_min'], $budgetMin > 0 ? $budgetMin : $spec['max_min']);
        $idealPeak  = round(min($spec['km'], $minutesCap * 60 / $longSec), 1);

        $weeks = $this->weekKeys($today, CarbonImmutable::parse($event->event_date), $spec['taper_days']);
        if (! $weeks['build']) {
            return null;
        }

        // Von wo aus gestartet wird. Wer noch nie lang gelaufen ist, fängt
        // nicht bei null an, sondern bei einer Stunde auf den Beinen.
        $current = max($volume['longest_run'] ?? 0, round(60 * 60 / $longSec, 1));

        [$ladder, $kinds, $peak] = $this->climb($current, $idealPeak, count($weeks['build']));

        $plan = [];
        foreach ($weeks['build'] as $i => $key) {
            $km = $ladder[$i];
            $plan[$key] = [
                'km'    => $km,
                'min'   => (int) round($km * $longSec / 60),
                'kind'  => $kinds[$i],
                'mp_km' => $spec['race_pace'] ? $this->racePaceKm($km, $peak) : 0.0,
            ];
        }

        // Nach dem längsten Lauf wird der lange Lauf kürzer, nicht langsamer.
        foreach ($weeks['taper'] as $i => $key) {
            $factor = self::TAPER_FACTORS[$i] ?? end(self::TAPER_FACTORS);
            $km     = round($peak * $factor, 1);
            $plan[$key] = [
                'km'    => $km,
                'min'   => (int) round($km * $longSec / 60),
                'kind'  => 'taper',
                'mp_km' => $spec['race_pace'] ? $this->clampRacePace($km * 0.3, 8.0) : 0.0,
            ];
        }

        return [
            'weeks'         => $plan,
            'peak_km'       => $peak,
            'ideal_peak_km' => $idealPeak,
            'reachable'     => $peak >= $idealPeak - 0.5,
            'verdict'       => $this->verdict($event, $current, $peak, $idealPeak, $spec, $minutesCap, count($weeks['build'])),
        ];
    }

    /**
     * Die Leiter hochsteigen. Zurückgegeben wird die Liste der Distanzen und
     * die Höhe, die dabei tatsächlich erreicht wird — die kann unter dem
     * Ideal liegen, wenn die Wochen nicht reichen.
     *
     * @return array{0: list<float>, 1: list<string>, 2: float}
     */
    private function climb(float $current, float $idealPeak, int $steps): array
    {
        $ladder = [];
        $kinds  = [];
        $last   = $current;

        for ($i = 0; $i < $steps; $i++) {
            $isPeak    = $i === $steps - 1;
            $isCutback = ! $isPeak && ($i + 1) % self::CUTBACK_EVERY === 0;

            if ($isCutback) {
                // Die Entlastung bricht die Leiter nicht ab: der nächste
                // Aufbauschritt geht vom letzten Aufbauwert weiter, nicht
                // vom reduzierten.
                $ladder[] = round(min($last, $idealPeak) * self::CUTBACK_FACTOR, 1);
                $kinds[]  = 'cutback';
                continue;
            }

            $step     = max(self::STEP_MIN_KM, $last * self::STEP_PCT);
            $last     = round(min($idealPeak, $last + $step), 1);
            $ladder[] = $last;
            $kinds[]  = $isPeak ? 'peak' : 'build';
        }

        // Ist der Deckel schon vor der letzten Woche erreicht, stünden zwei
        // gleich lange Läufe hintereinander. Der frühere wird dann zur
        // Entlastung — ausgeruht in den längsten Lauf ist besser als zweimal
        // dieselbe Distanz.
        $n = count($ladder);
        if ($n >= 2 && $ladder[$n - 2] >= $ladder[$n - 1]) {
            $ladder[$n - 2] = round($ladder[$n - 1] * self::CUTBACK_FACTOR, 1);
            $kinds[$n - 2]  = 'cutback';
        }

        return [$ladder, $kinds, $last];
    }

    /** Ab welcher Höhe der lange Lauf Abschnitte im Renntempo trägt. */
    private function racePaceKm(float $km, float $peak): float
    {
        if ($peak <= 0 || $km < $peak * 0.6) {
            return 0.0;
        }

        return $this->clampRacePace($km * 0.3, 12.0);
    }

    /** Ein Renntempo-Abschnitt unter drei Kilometern lohnt die Mühe nicht. */
    private function clampRacePace(float $km, float $max): float
    {
        $km = round(min($max, $km), 1);

        return $km >= self::MIN_RACE_PACE_KM ? $km : 0.0;
    }

    /**
     * Wochenschlüssel bis zum Rennen, aufgeteilt in Aufbau und Taper. Die
     * Rennwoche selbst bekommt keinen langen Lauf.
     *
     * @return array{build: list<string>, taper: list<string>}
     */
    private function weekKeys(CarbonImmutable $today, CarbonImmutable $raceDate, int $taperDays): array
    {
        $keys = [];
        $week = $today->startOfWeek();
        $race = $raceDate->startOfWeek();

        while ($week->lessThan($race)) {
            $keys[] = $week->format('o-\WW');
            $week   = $week->addWeek();
        }

        // Der längste Lauf liegt eine feste Zahl von Tagen vor dem Rennen —
        // beim Marathon drei Wochen. Über Wochen zu zählen ging daneben,
        // sobald das Rennen nicht am Wochenanfang lag: der Peak rutschte
        // eine Woche zu früh und der Aufbau verlor eine Woche.
        $peakWeek = $raceDate->subDays($taperDays)->startOfWeek()->format('o-\WW');
        $peakIdx  = array_search($peakWeek, $keys, true);

        if ($peakIdx === false) {
            // Das Rennen ist so nah, dass gar keine Aufbauwoche mehr bleibt.
            return ['build' => array_slice($keys, 0, 1), 'taper' => array_values(array_slice($keys, 1))];
        }

        return [
            'build' => array_slice($keys, 0, $peakIdx + 1),
            'taper' => array_values(array_slice($keys, $peakIdx + 1)),
        ];
    }

    private function verdict(Event $event, float $current, float $peak, float $idealPeak, array $spec, int $minutesCap, int $steps): string
    {
        $needed = $steps > 0 ? ($idealPeak - $current) / $steps : 0;
        $label  = $event->distance_label;

        // Zwei ganz verschiedene Gründe, warum der lange Lauf zu kurz bleibt:
        // zu wenig Wochen — oder zu wenig Zeit an einem einzigen Tag. Der
        // zweite lässt sich nicht wegtrainieren, sondern nur umräumen.
        if ($idealPeak < $spec['km'] - 0.5) {
            $ideal = $spec['km'];

            return "Der längste verfügbare Tag der Woche gibt {$minutesCap} Minuten her — mehr als {$idealPeak} km passen dort nicht hinein. "
                . "Für einen {$label} wären {$ideal} km nötig. Das ist kein Trainingsproblem, sondern ein Kalenderproblem: "
                . "sage dem Athleten in der description des längsten Laufs, dass er an einem Tag der Woche mehr Zeit braucht, sonst bleibt die Vorbereitung unvollständig. "
                . "Plane die Leiter bis zu dem, was hineinpasst.";
        }

        if ($peak >= $idealPeak - 0.5) {
            return $needed > self::SAFE_STEP_KM
                ? "Der lange Lauf erreicht rechtzeitig {$peak} km, aber nur mit sportlichen Sprüngen. Halte die Entlastungswochen deshalb strikt ein und plane nach jedem langen Lauf einen wirklich lockeren Tag."
                : "Der lange Lauf erreicht rechtzeitig {$peak} km. Die Leiter trägt — halte dich daran, auch wenn sich der Athlet stärker fühlt.";
        }

        $missing = round($idealPeak - $peak, 1);

        return "Bis zum Rennen reicht der lange Lauf nur bis {$peak} km statt der für einen {$label} nötigen {$idealPeak} km — es fehlen {$missing} km, und die Wochen dafür sind nicht mehr da. "
            . "Plane die Leiter trotzdem konsequent, aber sage es dem Athleten in der description des längsten Laufs offen: "
            . "Mit dieser Vorbereitung ist die Zielzeit unwahrscheinlich, und die letzten Kilometer werden hart. Ein realistischeres Ziel oder ein späteres Rennen wäre die ehrlichere Antwort. Nicht beschönigen.";
    }

    /** Der Abschnitt für den Prompt. */
    public function toPromptSection(array $plan): string
    {
        $labels = ['build' => 'Aufbau', 'cutback' => 'Entlastung', 'peak' => 'längster Lauf', 'taper' => 'Taper'];
        $lines  = [];

        foreach ($plan['weeks'] as $week => $row) {
            $mp = $row['mp_km'] > 0 ? ", davon {$row['mp_km']} km im Zielrenntempo" : '';
            $lines[] = "- {$week}: {$row['km']} km (~{$row['min']} min){$mp} — {$labels[$row['kind']]}";
        }

        return "\n\n**Leiter der langen Läufe (vom Renntag rückwärts gerechnet, BINDEND):**\n"
            . implode("\n", $lines)
            . "\n\n{$plan['verdict']}"
            . "\n- Die Distanz des langen Laufs steht damit fest. Weiche nicht ab — weder nach oben, weil der Athlet sich stark fühlt, noch nach unten, weil die Zahl groß aussieht.\n"
            . "- Die Abschnitte im Zielrenntempo gehören ans Ende des Laufs, nicht an den Anfang: erst locker einlaufen, dann das Renntempo auf müden Beinen. Genau darum geht es.\n"
            . "- Passt die Distanz nicht ins Zeitbudget des Tages, gilt die Dauer — dann wird der Lauf langsamer gelaufen, nicht kürzer geplant.";
    }
}
