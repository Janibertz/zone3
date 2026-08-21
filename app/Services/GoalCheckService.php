<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Prüft wöchentlich, ob die Zielzeit noch zum Athleten passt.
 *
 * Ein Trainingsplan steht auf einer Annahme: dass das Ziel erreichbar ist.
 * Diese Annahme hat bisher niemand nachgerechnet — der Athlet trägt beim
 * Anlegen des Events eine Zahl ein, und der Plan zieht sie monatelang
 * durch. Wer selbst merkt, dass sein Ziel nicht mehr stimmt, braucht dafür
 * keinen Coach; der Sinn eines Coachs ist, es vor einem zu merken.
 *
 * **Das Urteil steht auf zwei Beinen, und das ist der ganze Punkt.**
 *
 * Die Prognose aus der Schwellenpace beschreibt das Tempo — was der Motor
 * hergibt. Sie sagt nichts darüber, ob der Athlet dieses Tempo über die
 * Distanz halten kann. Ein Läufer mit 4:22 Schwellenpace und 25
 * Wochenkilometern bekommt eine Marathonprognose von 3:26, und die ist als
 * Tempoaussage richtig und als Rennaussage falsch. Ein Check, der nur die
 * Prognose hochrechnet, würde genau dort „passt schon" sagen — mit einer
 * Zahl, die Sicherheit vortäuscht.
 *
 * Deshalb kommt die Ausdauer dazu: Wochenumfang und längster Lauf gegen
 * das, was die Distanz verlangt. Erst wenn beide Beine dasselbe sagen, ist
 * die Aussage belastbar. Sagen sie Verschiedenes, benennt die Frage genau
 * das — statt einer Empfehlung, die eine Hälfte verschweigt.
 */
class GoalCheckService
{
    /**
     * Was die Distanz an Ausdauer verlangt, wenn man sie ernsthaft laufen
     * will: Wochenumfang und längster Lauf. Bewusst als Richtwert für ein
     * ambitioniertes Rennen — daran wird gemessen, nicht bestanden.
     */
    private const NEEDS = [
        'marathon'      => ['weekly' => 60.0, 'long' => 30.0, 'penalty' => 0.15],
        'half_marathon' => ['weekly' => 40.0, 'long' => 18.0, 'penalty' => 0.08],
        '10km'          => ['weekly' => 30.0, 'long' => 14.0, 'penalty' => 0.04],
        '5km'           => ['weekly' => 25.0, 'long' => 10.0, 'penalty' => 0.03],
    ];

    /** Ab dieser Abweichung lohnt die Frage. Darunter ist es Rauschen. */
    private const ASK_FASTER_SEC = 8;    // Ziel ist schneller als die Form
    private const ASK_SLOWER_SEC = 20;   // Ziel ist deutlich langsamer

    /** Darunter trägt der Unterbau die Zielzeit nicht. */
    private const THIN_BASE = 0.6;

    /** So kurz vor dem Rennen ist die Zielzeit Renntaktik, keine Planungsfrage. */
    private const QUIET_DAYS_BEFORE_RACE = 14;

    public function __construct(
        private readonly RacePredictionService $prediction,
        private readonly WeeklyVolumeService $volume,
    ) {}

    /**
     * @return array{
     *     kind: string, headline: string, detail: string,
     *     target: string, predicted: string, suggested: string|null,
     *     suggested_hours: int|null, suggested_minutes: int|null,
     *     weekly_km: float, longest_km: float, needs_weekly: float, needs_long: float
     * }|null  null, wenn es nichts zu fragen gibt
     */
    public function forEvent(User $user, Event $event, ?CarbonImmutable $today = null): ?array
    {
        $today = $today ?? CarbonImmutable::today();
        $spec  = self::NEEDS[$event->race_distance] ?? null;

        // Backyard hat keine Zielzeit, die man verfehlen könnte.
        if (! $spec || $event->isBackyard()) {
            return null;
        }

        $targetSec = ($event->target_time_hours * 60 + $event->target_time_minutes) * 60;
        $threshold = $user->runnerProfile?->threshold_speed;

        if ($targetSec <= 0 || ! $threshold) {
            return null;
        }

        $daysLeft = $event->days_until;
        if ($daysLeft < self::QUIET_DAYS_BEFORE_RACE) {
            return null;
        }

        $predicted = $this->prediction->fromThreshold($threshold, $event->race_distance);
        if (! $predicted) {
            return null;
        }

        $km          = RacePredictionService::ANCHORS[$event->race_distance]['km'];
        $targetPace  = $targetSec / $km;
        $predictPace = $predicted['total_sec'] / $km;

        // Positiv: das Ziel verlangt mehr Tempo, als die Form hergibt.
        $deltaSec = (int) round($predictPace - $targetPace);

        $volume    = $this->volume->forUser($user->id, $today);
        $longestKm = $volume['longest_run'] ?? 0.0;

        // Der Median, nicht der Mittelwert.
        //
        // Wer drei ruhige Wochen um 25 km läuft und dazu einen Backyard über
        // 69 km, hat einen Schnitt von 44 — und einen Alltag von 25. Der
        // Mittelwert hätte hier „Unterbau reicht" gesagt und die Frage
        // verschluckt, die genau dieser Athlet braucht. Umfang ist eine Frage
        // der Wiederholung, und ein einzelner großer Tag ist keine.
        //
        // Beim längsten Lauf bleibt es beim Maximum: dort ist die Spitze die
        // richtige Kennzahl. Man hat 30 km geschafft oder nicht.
        $weeklyKm = $volume['median_km'] ?? 0.0;

        // Der schwächere der beiden Werte bestimmt die Ausdauer — ein langer
        // Lauf allein trägt keinen Marathon, und Wochenkilometer ohne langen
        // Lauf auch nicht.
        $endurance = min(
            $spec['weekly'] > 0 ? $weeklyKm / $spec['weekly'] : 1.0,
            $spec['long']   > 0 ? $longestKm / $spec['long']  : 1.0,
        );

        $verdict = $this->verdict($deltaSec, $endurance, $volume['has_data'] ?? false);
        if ($verdict === null) {
            return null;
        }

        $suggestedSec = $this->suggest($verdict, $predicted['total_sec'], $targetSec, $endurance, $spec);

        return [
            'kind'      => $verdict,
            'headline'  => $this->headline($verdict, $event),
            'detail'    => $this->detail($verdict, $deltaSec, $weeklyKm, $longestKm, $spec, $predicted, $daysLeft),
            'target'    => $this->clock($targetSec),
            'predicted' => $predicted['time'],
            'suggested' => $suggestedSec ? $this->clock($suggestedSec) : null,
            'suggested_hours'   => $suggestedSec ? intdiv($suggestedSec, 3600) : null,
            'suggested_minutes' => $suggestedSec ? intdiv($suggestedSec % 3600, 60) : null,
            'weekly_km'    => $weeklyKm,
            'longest_km'   => $longestKm,
            'needs_weekly' => $spec['weekly'],
            'needs_long'   => $spec['long'],
        ];
    }

    /**
     * Welche Art von Frage sich stellt — oder keine.
     *
     * Die interessanteste ist `pace_ok_base_thin`: Tempo und Ausdauer sagen
     * Verschiedenes. Genau dort ist eine einzelne Zahl als Antwort falsch.
     */
    private function verdict(int $deltaSec, float $endurance, bool $hasVolumeData): ?string
    {
        if (! $hasVolumeData) {
            return null; // Ohne Laufdaten ist jede Aussage geraten.
        }

        if ($deltaSec > self::ASK_FASTER_SEC) {
            return 'too_ambitious';
        }

        if ($endurance < self::THIN_BASE) {
            return 'pace_ok_base_thin';
        }

        if ($deltaSec < -self::ASK_SLOWER_SEC && $endurance >= 0.8) {
            return 'too_conservative';
        }

        return null;
    }

    /**
     * Ein Vorschlag, keine Wahrheit. Bei zu ehrgeizigen Zielen bekommt die
     * Prognose einen Aufschlag für den fehlenden Unterbau — beim Marathon
     * mehr als beim 5000er, weil dort der Umfang über das Rennen entscheidet.
     */
    private function suggest(string $verdict, int $predictedSec, int $targetSec, float $endurance, array $spec): ?int
    {
        if ($verdict === 'too_conservative') {
            return $this->roundToFive($predictedSec);
        }

        $shortfall = max(0.0, 0.8 - $endurance);
        $suggested = (int) round($predictedSec * (1 + $shortfall * $spec['penalty']));

        // Einen schnelleren Vorschlag als das bestehende Ziel gibt es hier
        // nicht — sonst widerspricht sich die Frage selbst.
        return $suggested > $targetSec ? $this->roundToFive($suggested) : null;
    }

    /** Zielzeiten sind Verabredungen, keine Messwerte — auf fünf Minuten gerundet. */
    private function roundToFive(int $seconds): int
    {
        return (int) (round($seconds / 300) * 300);
    }

    private function headline(string $verdict, Event $event): string
    {
        return match ($verdict) {
            'too_ambitious'     => "Passt deine Zielzeit für {$event->name} noch?",
            'too_conservative'  => "Du bist schneller als dein Ziel für {$event->name}",
            'pace_ok_base_thin' => "Dein Tempo trägt {$event->name} — dein Umfang noch nicht",
        };
    }

    private function detail(string $verdict, int $deltaSec, float $weekly, float $longest, array $spec, array $predicted, int $daysLeft): string
    {
        $weeks = max(1, (int) ceil($daysLeft / 7));

        return match ($verdict) {
            'too_ambitious' => sprintf(
                'Deine Schwellenpace trägt derzeit %s. Dein Ziel verlangt %d Sekunden je Kilometer mehr, und in %d Wochen lässt sich das nicht mehr aufholen.',
                $predicted['time'], $deltaSec, $weeks,
            ),
            'too_conservative' => sprintf(
                'Deine Schwellenpace trägt %s, und dein Unterbau passt dazu — typische Woche %s km, längster Lauf %s km. Dein Ziel liegt %d Sekunden je Kilometer darunter.',
                $predicted['time'], $this->num($weekly), $this->num($longest), abs($deltaSec),
            ),
            'pace_ok_base_thin' => sprintf(
                'Vom Tempo her passt es: die Schwellenpace trägt %s. Der Unterbau nicht — %s km pro Woche und ein längster Lauf von %s km, nötig wären etwa %s und %s km. Der Marathon bestraft nicht das fehlende Tempo, sondern die fehlende Wiederholung.',
                $predicted['time'], $this->num($weekly), $this->num($longest),
                $this->num($spec['weekly']), $this->num($spec['long']),
            ),
        };
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');
    }

    private function clock(int $sec): string
    {
        return sprintf('%d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60));
    }
}
