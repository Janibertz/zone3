<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Event;
use Carbon\CarbonImmutable;

/**
 * Wie viel der Athlet tatsächlich läuft — Woche für Woche.
 *
 * Im Plan-Prompt stand davon bisher nichts. Er kannte die letzten zehn
 * Aktivitäten einzeln, aber nirgends die Summe: keine Wochenkilometer, keine
 * Entwicklung, nicht den längsten Lauf der letzten Wochen. Genau das sind
 * aber die Größen, aus denen ein Trainingsplan gebaut wird. Ohne sie musste
 * das Modell die Umfänge raten, und es riet konservativ und schwankend —
 * eine Woche 40 km, die nächste 60, ohne dass sich am Athleten etwas
 * geändert hätte.
 *
 * Gezählt werden nur Läufe. Radfahren und Spaziergänge stehen getrennt, weil
 * sie die Laufbelastung nicht ersetzen.
 */
class WeeklyVolumeService
{
    private const WEEKS = 6;

    /** So viel darf der Wochenumfang höchstens wachsen. */
    private const MAX_PROGRESSION_PCT = 10;

    /**
     * @return array{
     *     has_data: bool, weeks: list<array{label: string, km: float, runs: int, longest: float}>,
     *     avg_km: float, last_km: float, trend_pct: float|null,
     *     longest_run: float, next_week_max: float
     * }
     */
    public function forUser(int $userId, ?CarbonImmutable $today = null): array
    {
        $today = $today ?? CarbonImmutable::today();
        $from  = $today->startOfWeek()->subWeeks(self::WEEKS - 1);

        $runs = Activity::where('user_id', $userId)
            ->where('type', 'Run')
            ->where('distance', '>=', 1000)
            ->where('start_date', '>=', $from->toDateTimeString())
            ->orderBy('start_date')
            ->get(['id', 'start_date', 'distance', 'moving_time']);

        $weeks = [];
        for ($i = 0; $i < self::WEEKS; $i++) {
            $start = $from->addWeeks($i);
            $key   = $start->format('o-\WW');

            $weeks[$key] = [
                'label'   => $start->format('d.m.'),
                'km'      => 0.0,
                'runs'    => 0,
                'longest' => 0.0,
                'current' => $start->format('o-\WW') === $today->format('o-\WW'),
            ];
        }

        foreach ($runs as $run) {
            $key = CarbonImmutable::parse($run->start_date)->format('o-\WW');
            if (! isset($weeks[$key])) {
                continue;
            }

            $km = $run->distance / 1000;

            $weeks[$key]['km']      = round($weeks[$key]['km'] + $km, 1);
            $weeks[$key]['runs']++;
            $weeks[$key]['longest'] = round(max($weeks[$key]['longest'], $km), 1);
        }

        // Die laufende Woche ist noch nicht vorbei — sie verzerrt jeden
        // Schnitt und jeden Trend. Für beides zählen nur volle Wochen.
        $complete = array_values(array_filter($weeks, fn ($w) => ! $w['current']));
        $withRuns = array_values(array_filter($complete, fn ($w) => $w['runs'] > 0));

        if (! $withRuns) {
            return [
                'has_data' => false, 'weeks' => array_values($weeks),
                'avg_km' => 0.0, 'last_km' => 0.0, 'trend_pct' => null,
                'longest_run' => 0.0, 'next_week_max' => 0.0,
            ];
        }

        $recent  = array_slice($withRuns, -4);
        $avg     = round(array_sum(array_column($recent, 'km')) / count($recent), 1);
        $last    = (float) end($complete)['km'];
        $longest = round(max(array_column($complete, 'longest')), 1);

        return [
            'has_data'      => true,
            'weeks'         => array_values($weeks),
            'avg_km'        => $avg,
            'last_km'       => $last,
            'trend_pct'     => $avg > 0 ? round(($last - $avg) / $avg * 100) : null,
            'longest_run'   => $longest,
            'next_week_max' => round(max($avg, $last) * (1 + self::MAX_PROGRESSION_PCT / 100), 1),
        ];
    }

    /**
     * Der Abschnitt für den Prompt — samt der Regel, wie weit der Umfang
     * wachsen darf. Ohne diese Grenze plante das Modell Sprünge, die jeder
     * Coach als Verletzungsrisiko erkennen würde.
     */
    public function toPromptSection(array $v, Event $event, ?float $nextLongRunKm = null): string
    {
        if (! $v['has_data']) {
            return "\n\n**Wochenumfang:** keine Laufdaten der letzten Wochen — steige vorsichtig ein und steigere den Umfang erst, wenn Einheiten tatsächlich absolviert werden.";
        }

        $lines = [];
        foreach ($v['weeks'] as $w) {
            $mark = $w['current'] ? ' (laufende Woche, noch unvollständig)' : '';

            if ($w['runs'] === 0) {
                $lines[] = "- Woche ab {$w['label']}: keine Läufe{$mark}";
                continue;
            }

            $runs    = $w['runs'] === 1 ? '1 Lauf' : "{$w['runs']} Läufen";
            $longest = ", längster Lauf {$w['longest']} km";
            $lines[] = "- Woche ab {$w['label']}: {$w['km']} km in {$runs}{$longest}{$mark}";
        }

        $trend = $v['trend_pct'] === null
            ? ''
            : ($v['trend_pct'] >= 0
                ? " Die letzte volle Woche lag {$v['trend_pct']} % über dem Schnitt."
                : ' Die letzte volle Woche lag ' . abs($v['trend_pct']) . ' % unter dem Schnitt.');

        $weeksLeft = $event->weeks_until;

        // Ein langer Lauf, der allein schon an die Wochengrenze stößt, macht
        // die Grenze unsinnig. Dann steigt sie mit ihm — der lange Lauf ist
        // das Rückgrat der Woche, nicht ihr Rest.
        $ceiling = $nextLongRunKm > 0
            ? max($v['next_week_max'], round($nextLongRunKm * 1.8, 1))
            : $v['next_week_max'];

        return "\n\n**Wochenumfang (nur Läufe):**\n" . implode("\n", $lines)
            . "\n\nDurchschnitt der letzten vollen Wochen: {$v['avg_km']} km. Längster Lauf im Zeitraum: {$v['longest_run']} km.{$trend}"
            . "\n\n**Regeln zum Umfang (bindend):**\n"
            . "- Der Wochenumfang der kommenden Woche darf {$ceiling} km NICHT überschreiten (max. " . self::MAX_PROGRESSION_PCT . " % Steigerung), den langen Lauf eingerechnet. Wer krank war oder Einheiten abgesagt hat, steigert gar nicht, sondern hält oder reduziert.\n"
            . "- Der lange Lauf ist davon ausgenommen: seine Distanz steht in der Leiter weiter unten und geht vor. Der Rest der Woche füllt auf, was danach noch übrig ist.\n"
            . "- Jede dritte bis vierte Woche ist eine Entlastungswoche mit rund 25 % weniger Umfang — plane sie ein, wenn die letzten Wochen durchgehend gestiegen sind.\n"
            . "- Etwa 80 % des Wochenumfangs sind locker (Zone 1–2), höchstens 20 % intensiv. Das gilt über die Woche, nicht über die einzelne Einheit.\n"
            . "- Rechne die geplanten Distanzen selbst zusammen und halte diese Summe ein. Noch {$weeksLeft} Wochen bis zum Rennen — der Umfang muss dorthin führen, nicht ins Übertraining.";
    }
}
