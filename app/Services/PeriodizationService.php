<?php

namespace App\Services;

use App\Models\Event;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;

/**
 * Wo im Trainingsjahr eine Woche liegt — rückwärts vom Renntag gerechnet.
 *
 * Bis hierhin wusste der Plan nur, in welcher Phase der Athlet *heute*
 * steht (`Event::training_phase`), und der Prompt bat darum, „jede dritte
 * bis vierte Woche" zu entlasten. Durchgesetzt hat das niemand. Die Leiter
 * der langen Läufe hatte zwar eine Entlastung, zählte sie aber ab heute:
 * jede dritte Woche ab dem Planungstag. Bei einem rollierenden 14-Tage-Plan,
 * der jeden Sonntag neu ansetzt, kam diese dritte Woche nie an — die
 * Entlastung lag immer knapp hinter dem Fenster.
 *
 * Hier hängt jede Woche fest am Renntag:
 *
 *   Base → Build → Peak → Taper → Race Week
 *
 * Die Entlastung folgt einem 3:1-Rhythmus, vom längsten Lauf aus rückwärts
 * gezählt. Die Woche des längsten Laufs ist immer eine Belastungswoche,
 * drei Wochen davor liegt die nächste Entlastung. Damit steht die
 * Entlastungswoche fest, egal an welchem Sonntag neu geplant wird.
 *
 * Pro Woche beantwortet die Klasse drei Fragen, und alle anderen lesen hier:
 *   · Umfangsfaktor   → {@see WeeklyPatternService::applyVolumeBudget()}
 *   · Entlastung      → {@see LongRunPlanService} (der lange Lauf wird kürzer)
 *   · harte Einheiten → welche Typen und wie viele ({@see WeeklyPatternService::planWeek()})
 */
class PeriodizationService
{
    /** Die letzte Woche vor dem Rennen (Anzeige nach Tagen). */
    public const RACE_WEEK_DAYS = 7;

    /** Spezifische Phase vor dem Taper: sechs Wochen Renntempo-Arbeit. */
    public const PEAK_PHASE_DAYS = 42;

    /** Aufbau davor: sechs Wochen. */
    public const BUILD_PHASE_DAYS = 42;

    /** 3:1 — drei Wochen Belastung, eine Woche Entlastung. */
    public const RECOVERY_EVERY = 4;

    /**
     * Vor der ersten Entlastung stehen mindestens drei Belastungswochen ab
     * Planstart (Entscheidung von Jan, 30.10.2026). Der 3:1-Rhythmus hängt am
     * Renntag und weiß nichts vom Planstart — bei Jans Plan (Start 30.11.)
     * fiel die erste Entlastung zufällig in Woche 2. Eine solche Woche fällt
     * aus; der Rhythmus danach bleibt, wie er ist.
     */
    public const LOAD_WEEKS_BEFORE_FIRST_RECOVERY = 3;

    /**
     * In einer Entlastungswoche wird das Laufen etwa halbiert (vorher ein
     * Viertel weniger). So stand es in Jans Coach-Plan bei Mats, Woche 4, 8,
     * 12, 16 und 20: rund 3:50 h statt 7:30 h — Rad und Kraft bleiben.
     */
    public const RECOVERY_FACTOR = 0.5;

    /**
     * Umfang im Taper, nach Wochen bis zur Rennwoche: in der Rennwoche die
     * Hälfte, eine Woche davor 70 %, zwei davor 85 %. Mujika: 40–60 %
     * weniger über zwei Wochen, Intensität bleibt.
     */
    private const TAPER_FACTORS = [0 => 0.5, 1 => 0.7, 2 => 0.85, 3 => 0.9];

    /**
     * Was jede Phase an harten Einheiten zulässt.
     *
     * In der Grundlage keine Intervalle: dort entsteht die aerobe Basis,
     * Schwelle und Steigerungsläufe reichen als Reiz. Im Taper bleibt die
     * Intensität (Taper heißt Umfang runter, nicht Tempo runter), in der
     * Rennwoche genau ein kurzer Renntempo-Reiz.
     */
    private const PHASES = [
        'base'      => ['label' => 'Base',      'color' => 'green',  'quality' => ['tempo_run', 'progressive_run'],             'max_hard' => 1],
        'build'     => ['label' => 'Build',     'color' => 'blue',   'quality' => ['tempo_run', 'interval', 'progressive_run'], 'max_hard' => 2],
        'peak'      => ['label' => 'Peak',      'color' => 'orange', 'quality' => ['tempo_run', 'interval', 'progressive_run'], 'max_hard' => 2],
        'taper'     => ['label' => 'Taper',     'color' => 'yellow', 'quality' => ['tempo_run', 'interval', 'progressive_run'], 'max_hard' => 2],
        'race_week' => ['label' => 'Race Week', 'color' => 'red',    'quality' => ['tempo_run', 'interval', 'progressive_run'], 'max_hard' => 1],
        'past'      => ['label' => 'Vergangenheit', 'color' => 'gray', 'quality' => ['tempo_run', 'interval', 'progressive_run'], 'max_hard' => 2],
    ];

    /**
     * Die Phase an einem Tag — für die Anzeige auf Dashboard und Kalender.
     *
     * Nach Tagen, nicht nach Wochen: „Race Week" heißt für den Athleten die
     * letzten sieben Tage, egal auf welchen Wochentag das Rennen fällt. Der
     * Plan rechnet mit {@see forWeek()}; beide benutzen dieselben Grenzen.
     *
     * @return array{key: string, label: string, color: string}
     */
    public function phaseOn(Event $event, ?CarbonImmutable $day = null): array
    {
        $day   = ($day ?? CarbonImmutable::today())->startOfDay();
        $race  = CarbonImmutable::parse($event->event_date)->startOfDay();
        $days  = (int) round($day->diffInDays($race, false));
        $taper = LongRunPlanService::taperDays($event->race_distance);

        $key = match (true) {
            $days < 0                                                   => 'past',
            $days <= self::RACE_WEEK_DAYS                               => 'race_week',
            $days <= $taper                                             => 'taper',
            $days <= $taper + self::PEAK_PHASE_DAYS                     => 'peak',
            $days <= $taper + self::PEAK_PHASE_DAYS + self::BUILD_PHASE_DAYS => 'build',
            default                                                     => 'base',
        };

        return ['key' => $key, 'label' => self::PHASES[$key]['label'], 'color' => self::PHASES[$key]['color']];
    }

    /**
     * Die Phasen von heute bis zum Rennen als Abschnitte in Tagen — für den
     * Phasenbalken oben auf der Planseite. Vergangene Phasen fallen weg, die
     * laufende ist auf den Rest gekürzt. Dieselben Grenzen wie {@see phaseOn()}.
     *
     * @return list<array{key: string, days: int}>
     */
    public function segments(Event $event, ?CarbonImmutable $day = null): array
    {
        $day   = ($day ?? CarbonImmutable::today())->startOfDay();
        $left  = (int) round($day->diffInDays(CarbonImmutable::parse($event->event_date)->startOfDay(), false));
        if ($left < 0) {
            return [];
        }

        $taper = LongRunPlanService::taperDays($event->race_distance);
        // Obergrenze je Phase in Tagen vor dem Rennen, vom Rennen aus gezählt.
        $bounds = [
            'race_week' => self::RACE_WEEK_DAYS,
            'taper'     => $taper,
            'peak'      => $taper + self::PEAK_PHASE_DAYS,
            'build'     => $taper + self::PEAK_PHASE_DAYS + self::BUILD_PHASE_DAYS,
            'base'      => PHP_INT_MAX,
        ];

        $out   = [];
        $start = 0;
        foreach ($bounds as $key => $end) {
            $days = max(0, min($end, $left + 1) - $start);
            if ($days > 0) {
                $out[] = ['key' => $key, 'days' => $days];
            }
            $start = max($start, $end);
        }

        return array_reverse($out);
    }

    /**
     * Alle Wochen eines Planungsfensters, nach ISO-Woche (`2026-W44`).
     *
     * @return array<string, array>
     */
    public function forWindow(Event $event, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $weeks = [];
        for ($week = $from->startOfWeek(); $week->lte($to); $week = $week->addWeek()) {
            $weeks[$week->format('o-\WW')] = $this->forWeek($event, $week);
        }

        return $weeks;
    }

    /**
     * Eine Woche, gegeben durch einen beliebigen Tag darin.
     *
     * Die Taper-Grenze ist dieselbe wie in der Leiter der langen Läufe: die
     * Woche, in der der Tag `Renntag − Taperlänge` liegt, trägt den längsten
     * Lauf, alles danach ist Taper. Lägen beide Grenzen auch nur einen Tag
     * auseinander, stünde der längste Lauf in einer Taperwoche.
     *
     * @return array{
     *     phase: string, label: string, recovery: bool, volume_factor: float,
     *     quality: list<string>, max_hard: int, weeks_to_race: int
     * }
     */
    public function forWeek(Event $event, CarbonImmutable $day): array
    {
        $week     = $day->startOfWeek();
        $race     = CarbonImmutable::parse($event->event_date)->startOfDay();
        $raceWeek = $race->startOfWeek();
        $taper    = LongRunPlanService::taperDays($event->race_distance);
        $peakWeek = self::peakWeek($race, $taper);

        $weeksToRace = (int) round($week->diffInDays($raceWeek, false) / 7);
        $recovery    = false;
        $factor      = 1.0;

        if ($week->gt($raceWeek)) {
            $phase = 'past';
        } elseif ($week->eq($raceWeek)) {
            $phase  = 'race_week';
            $factor = self::TAPER_FACTORS[0];
        } elseif ($week->gt($peakWeek)) {
            $phase  = 'taper';
            $factor = self::TAPER_FACTORS[min($weeksToRace, 3)];
        } else {
            $days  = (int) round($week->diffInDays($race));
            $phase = match (true) {
                $days <= $taper + self::PEAK_PHASE_DAYS                          => 'peak',
                $days <= $taper + self::PEAK_PHASE_DAYS + self::BUILD_PHASE_DAYS => 'build',
                default                                                          => 'base',
            };

            // Vom längsten Lauf aus rückwärts: 0 = Peak-Woche (Belastung),
            // 1 und 2 Belastung, 3 Entlastung, dann wieder von vorn.
            $fromPeak = (int) round($week->diffInDays($peakWeek) / 7);
            //
            // Eine vorgezogene Entlastung (FatigueCheck) gilt in ihrer Woche;
            // die nächste aus dem Rhythmus fällt aus, wenn sie weniger als
            // drei Belastungswochen danach käme.
            $early    = $event->early_recovery_week
                ? CarbonImmutable::parse($event->early_recovery_week->toDateString())->startOfWeek()
                : null;
            $recovery = $fromPeak % self::RECOVERY_EVERY === self::RECOVERY_EVERY - 1
                && $week->gte($this->planBegan($event)->startOfWeek()->addWeeks(self::LOAD_WEEKS_BEFORE_FIRST_RECOVERY))
                && ! ($early && $week->gt($early) && $week->lte($early->addWeeks(self::LOAD_WEEKS_BEFORE_FIRST_RECOVERY)));
            $recovery = $recovery || ($early && $week->eq($early));
            $factor   = $recovery ? self::RECOVERY_FACTOR : 1.0;
        }

        $spec = self::PHASES[$phase];

        return [
            'phase'         => $phase,
            'label'         => $spec['label'],
            'recovery'      => $recovery,
            'volume_factor' => $factor,
            'quality'       => $spec['quality'],
            // Eine Entlastungswoche verträgt eine harte Einheit, nicht zwei.
            'max_hard'      => $recovery ? 1 : $spec['max_hard'],
            'weeks_to_race' => max(0, $weeksToRace),
        ];
    }

    /** Die Woche mit dem längsten Lauf — die einzige Definition dafür. */
    /**
     * Der erste Tag des Plans: das Startdatum aus dem Interview, sonst die
     * früheste Einheit des Rennens, sonst heute. Nicht
     * {@see Event::planStartsOn()} — das rückt bei einem laufenden Plan jeden
     * Tag auf heute vor, und die erste Entlastung käme nie.
     */
    private function planBegan(Event $event): CarbonImmutable
    {
        if (! $event->exists) {
            return CarbonImmutable::today();
        }

        if ($event->plan_start_date) {
            return CarbonImmutable::parse($event->plan_start_date->toDateString());
        }

        $first = TrainingSession::where('event_id', $event->id)->min('planned_date');

        return $first ? CarbonImmutable::parse($first)->startOfDay() : CarbonImmutable::today();
    }

    public static function peakWeek(CarbonImmutable $raceDate, int $taperDays): CarbonImmutable
    {
        return $raceDate->startOfDay()->subDays($taperDays)->startOfWeek();
    }

    /** Was jede Phase verlangt — für den Prompt. */
    private const RULES = [
        'base'      => 'Grundlage: lockeres Volumen aufbauen. Die eine harte Einheit wechselt wochenweise zwischen Schwelle und Fahrtspiel oder Bergintervallen — keine Bahnintervalle, kein Renntempo.',
        'build'     => 'Aufbau: Umfang steigt, dazu Schwellen- und Intervallarbeit; der lange Lauf wächst schrittweise und bekommt erste kurze Abschnitte im Renntempo.',
        'peak'      => 'Spezifische Phase: die Schlüsseleinheiten laufen jetzt im Renntempo. Höchster Umfang, längster Lauf, danach beginnt die Reduktion.',
        'taper'     => 'Tapering: Umfang zurück, Intensität und Renntempo aber halten. Weniger, nicht langsamer.',
        'race_week' => 'Rennwoche: Umfang stark zurück, Beine frisch machen. Drei bis vier Tage vor dem Rennen eine kurze Einheit mit wenigen Minuten im Zielrenntempo (z. B. 3×1 km), sonst kurze lockere Läufe mit ein paar Steigerungen. Keine langen oder ermüdenden Einheiten mehr — die Form ist gemacht.',
        'past'      => 'Trainingsblock fortsetzen.',
    ];

    /**
     * Der Abschnitt für den Prompt: jede Woche des Fensters mit Phase und
     * Entlastung, darunter die Regeln der vorkommenden Phasen.
     *
     * @param  array<string, array>  $weeks  Ergebnis von {@see forWindow()}
     */
    public function toPromptSection(array $weeks, int $weeksLeft): string
    {
        if (! $weeks) {
            return '';
        }

        $lines  = [];
        $phases = [];

        foreach ($weeks as $key => $w) {
            $extra = match (true) {
                $w['recovery']              => ' — ENTLASTUNGSWOCHE: Umfang rund ' . (int) round((1 - $w['volume_factor']) * 100) . ' % niedriger, höchstens eine harte Einheit',
                $w['volume_factor'] < 1.0   => ' — Umfang ' . (int) round($w['volume_factor'] * 100) . ' % einer normalen Woche',
                default                     => '',
            };

            $lines[]              = "- {$key}: {$w['label']}{$extra}";
            $phases[$w['phase']] = true;
        }

        $rules = array_map(fn ($p) => '- ' . self::RULES[$p], array_keys($phases));

        return "\n\n**Periodisierung (vom Renntag rückwärts gerechnet, BINDEND)** — noch {$weeksLeft} Wochen bis zum Rennen:\n"
            . implode("\n", $lines)
            . "\n\n" . implode("\n", $rules)
            . "\n- Entlastung und Taper sind im Wochengerüst bereits eingerechnet: die Zielumfänge dort sind schon reduziert. Reduziere nicht noch einmal und fülle die Woche nicht auf.";
    }
}
