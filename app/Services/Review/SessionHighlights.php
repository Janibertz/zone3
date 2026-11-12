<?php

namespace App\Services\Review;

/**
 * Die eine Sache, über die der Coach nach einer Einheit schreibt.
 *
 * Gemeldet von Jan (12.11.2026): die Nachricht nach einem Spaziergang zählte
 * Dauer, Strecke, beide Pulswerte, Höhenmeter, Temperatur, gefühlte
 * Temperatur und Wind auf, dazu die Umsetzungsquote der letzten vier Wochen —
 * „hört sich nicht so an, als käme sie von einem echten Trainer". Der Prompt
 * hatte es so bestellt: „konkret mit Zahlen", „ordne in den Verlauf ein".
 * Ein Trainer sagt eine Sache, die zählt.
 *
 * Welche das ist, entscheidet hier der Code, nicht das Modell — wie beim
 * Plan: das Gerüst steht fest, das Modell schreibt nur den Text. Die
 * Kandidaten werden in fester Reihenfolge geprüft; der erste ist der
 * Hauptpunkt, der zweite höchstens ein Nebenpunkt.
 */
class SessionHighlights
{
    /** Wie lang die Nachricht sein darf: Sätze höchstens. */
    public const SENTENCES = ['short' => 2, 'normal' => 3, 'long' => 4];

    /** Sportarten, zu denen der Coach nichts schreibt — Alltag, kein Training. */
    public const SILENT_SPORTS = ['Walk', 'Yoga', 'Pilates', 'Stretching', 'Meditation'];

    /** Wandern ab einer Stunde ist Belastung und bekommt eine Nachricht. */
    public const HIKE_SILENT_BELOW_MIN = 60;

    /** Einheiten, bei denen ein Abweichen vom Plan zählt. */
    public const KEY_TYPES = ['tempo_run', 'interval', 'long_run', 'progressive_run', 'test_run', 'race_prep', 'back_to_back_long'];

    /** Nach so vielen Tagen ohne Lauf ist der nächste ein Wiedereinstieg. */
    public const COMEBACK_DAYS = 10;

    /**
     * Ob zu dieser Einheit überhaupt eine Nachricht kommt.
     */
    public static function silent(?string $sport, ?int $minutes): bool
    {
        if (in_array($sport, self::SILENT_SPORTS, true)) {
            return true;
        }

        return $sport === 'Hike' && ($minutes ?? 0) < self::HIKE_SILENT_BELOW_MIN;
    }

    /**
     * @param  array{
     *   run?: bool, type?: string, sport?: ?string,
     *   pb?: ?array{distance: string, time: string, previous: string},
     *   race?: ?string,
     *   comeback_days?: ?int, pause_ended?: bool,
     *   rest_ignored?: bool, unplanned?: bool,
     *   pace_off_s?: ?int, km_off_pct?: ?int, min_off_pct?: ?int, as_planned?: bool,
     *   trend_pace_s?: ?int, trend_hr?: ?int,
     *   drift?: ?int, minutes?: ?int,
     *   temp?: ?float, apparent?: ?float, wind?: ?float,
     * }  $s
     * @return array{key: string, main: string, side: ?string, length: string, emoji: bool}
     */
    public static function pick(array $s): array
    {
        $found = [];
        $type  = $s['type'] ?? '';
        $run   = $s['run'] ?? true;
        $key   = in_array($type, self::KEY_TYPES, true);

        if (! empty($s['race'])) {
            $found[] = ['race', "Rennen: {$s['race']}. Gratuliere zum Rennen, frag nach dem Gefühl, sag etwas zur Erholung in den nächsten Tagen.", 'long', true];
        }

        if (! empty($s['pb'])) {
            $pb = $s['pb'];
            $found[] = ['pb', "Neue Bestzeit über {$pb['distance']}: {$pb['time']} (vorher {$pb['previous']}).", 'long', true];
        }

        if ($run && (! empty($s['pause_ended']) || ($s['comeback_days'] ?? 0) >= self::COMEBACK_DAYS)) {
            $since = ! empty($s['comeback_days']) ? "nach {$s['comeback_days']} Tagen ohne Lauf" : 'nach der Pause';
            $found[] = ['comeback', "Erster Lauf {$since}. Lob die Zurückhaltung, frag, wie der Körper es aufnimmt.", 'normal', false];
        }

        if (! empty($s['rest_ignored'])) {
            $found[] = ['rest_ignored', 'Geplant war ein Ruhetag, trainiert wurde trotzdem. Sag ehrlich, warum Ruhe zum Training gehört, ohne zu schimpfen.', 'normal', false];
        }

        $paceOff = $s['pace_off_s'] ?? null;
        if ($key && $paceOff !== null && abs($paceOff) >= 8) {
            $found[] = $paceOff < 0
                ? ['too_fast', abs($paceOff) . ' Sekunden pro km schneller als geplant. Erklär in einem Satz, warum das Tempo der Einheit wichtiger ist als schnell.', 'normal', false]
                : ['too_slow', "{$paceOff} Sekunden pro km langsamer als geplant. Ohne Drama, frag nach dem Grund.", 'normal', false];
        }

        $kmOff = $s['km_off_pct'] ?? $s['min_off_pct'] ?? null;
        if ($kmOff !== null && $kmOff >= 20) {
            $found[] = ['too_long', "{$kmOff} % länger als geplant. Mehr ist nicht automatisch besser; es kostet Erholung für die nächste Einheit.", 'normal', false];
        }

        $trendPace = $s['trend_pace_s'] ?? null;
        $trendHr   = $s['trend_hr'] ?? null;
        if ($run && $trendPace !== null && $trendHr !== null) {
            if ($trendPace >= -3 && $trendHr <= -3) {
                $found[] = ['fitter', 'Gleiches Tempo wie vor einem Monat, aber ' . abs($trendHr) . ' Schläge weniger Puls. Das ist Fortschritt; sag, was er für das Ziel bedeutet.', 'normal', false];
            } elseif ($trendPace >= 5 && $trendHr <= 2) {
                $found[] = ['fitter', "{$trendPace} Sekunden pro km schneller als vor einem Monat bei gleichem Puls. Das ist Fortschritt; sag, was er für das Ziel bedeutet.", 'normal', false];
            }
        }

        if ($run && ($s['drift'] ?? 0) >= 8 && ($s['minutes'] ?? 0) >= 45) {
            $found[] = ['drift', "Der Puls ist in der zweiten Hälfte um {$s['drift']} Schläge gestiegen. Nenne eine plausible Ursache (Trinken, Wärme, Müdigkeit) oder frag danach.", 'normal', false];
        }

        $temp = $s['temp'] ?? null;
        $feel = $s['apparent'] ?? $temp;
        $wind = $s['wind'] ?? null;
        if ($temp !== null && $temp >= 25) {
            $found[] = ['weather', 'Gelaufen bei ' . round($temp) . ' Grad. Die Wärme erklärt Puls und Tempo.', 'short', false];
        } elseif ($feel !== null && $feel <= -5) {
            $found[] = ['weather', 'Gefühlt ' . round($feel) . ' Grad. Anerkennung, dass die Einheit trotzdem stattfand.', 'short', false];
        } elseif ($wind !== null && $wind >= 30) {
            $found[] = ['weather', 'Wind mit ' . round($wind) . ' km/h. Das erklärt ein langsameres Tempo.', 'short', false];
        }

        if ($key && ! empty($s['as_planned'])) {
            $found[] = ['on_target', 'Schlüsseleinheit sauber im Plan umgesetzt. Kurz anerkennen, was sie bringt.', 'short', false];
        }

        if (! $found) {
            $what = $run ? 'Routine, wie geplant erledigt' : 'Ergänzung zum Laufen, ohne Besonderheit';
            $found[] = ['routine', "{$what}. Ein kurzer, freundlicher Satz und ein Blick auf die nächste Einheit reichen.", 'short', false];
        }

        [$k, $main, $length, $emoji] = $found[0];

        return [
            'key'    => $k,
            'main'   => $main,
            'side'   => isset($found[1]) && $found[1][0] !== $k ? $found[1][1] : null,
            'length' => $length,
            'emoji'  => $emoji,
        ];
    }
}
