<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Einheiten ohne Plan: anhaengen, was dazugehoert — loeschen, was leer ist.
 *
 * Gefunden bei der Bestandsaufnahme am 09.10.2026: 68 Einheiten mit
 * `training_plan_id = NULL`, die NICHT als `was_unplanned` markiert sind. Sie
 * sind fuer den Athleten unsichtbar (die Planseite laedt nur Einheiten des
 * aktiven Plans), zaehlen aber in Statistiken und Wochenauswertungen mit.
 *
 * Die Anweisung war „hau die verwaisten Einheiten weg". Beim Nachsehen war
 * aber nicht Muell in der Menge, sondern **Trainingshistorie**: 51 erledigt,
 * 49 an echten Strava-Aktivitaeten, und **45 mit der eigenen Bewertung des
 * Athleten** — von 128 bewerteten Einheiten ueberhaupt. Diese Bewertungen
 * fuettern den Prompt-Abschnitt „Bisherige Einheitsbewertungen"; sie zu
 * loeschen haette ein Drittel des Athleten-Feedbacks gekostet, das der Plan
 * zum Lernen hat.
 *
 * 63 der 68 trugen eine `event_id`, und zu jedem dieser Events gibt es den
 * Plan noch. Der Defekt war also nie „diese Zeilen sind ueberfluessig",
 * sondern „die Verknuepfung ist verloren gegangen" — wohl beim Beenden oder
 * Neuberechnen eines Plans.
 *
 * Deshalb nach Regel und nicht nach Anzahl: die lokale Kopie ist vom
 * 08.09.2026, Produktion hat mehr Zeilen. Eine Migration, die „die 68"
 * loescht, loescht in Produktion etwas anderes.
 *
 *   1. Mit `event_id` → an den neuesten Plan dieses Events anhaengen.
 *   2. Ohne Plan und ohne jeden Inhalt (keine Aktivitaet, keine Bewertung,
 *      keine Notiz, keine Rezension, nicht erledigt, Termin vorbei) → weg.
 *   3. Alles andere bleibt stehen und wird als Warnung geloggt. Was Inhalt
 *      hat und sich nicht zuordnen laesst, entscheidet ein Mensch.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphans = fn () => DB::table('training_sessions')
            ->whereNull('training_plan_id')
            ->where('was_unplanned', false);

        $before = (clone $orphans())->count();

        if ($before === 0) {
            Log::info('Verwaiste Einheiten: keine vorhanden.');

            return;
        }

        // ── 1. Anhaengen, was eine Event-Zuordnung hat ──────────────────
        $adopted = 0;

        $eventIds = (clone $orphans())->whereNotNull('event_id')->distinct()->pluck('event_id');

        foreach ($eventIds as $eventId) {
            $planId = DB::table('training_plans')
                ->where('event_id', $eventId)
                ->orderByDesc('id')
                ->value('id');

            if (! $planId) {
                continue;
            }

            $adopted += (clone $orphans())->where('event_id', $eventId)
                ->update(['training_plan_id' => $planId, 'updated_at' => now()]);
        }

        // ── 2. Loeschen, was wirklich leer ist ──────────────────────────
        //
        // Jede Bedingung steht fuer etwas, das verloren gehen koennte:
        // `activity_id` ist ein gelaufener Lauf, `rating` und
        // `feeling_notes` sind Aussagen des Athleten, `coach_review` ist ein
        // bezahlter Modellaufruf. `status <> completed` und ein Termin in der
        // Vergangenheit stellen sicher, dass nichts Geplantes aus der Zukunft
        // mitgeht.
        $deletable = fn () => (clone $orphans())
            ->whereNull('activity_id')
            ->whereNull('rating')
            ->whereNull('feeling_notes')
            ->whereNull('coach_review')
            ->where('status', '<>', 'completed')
            ->whereDate('planned_date', '<', now()->toDateString());

        $removed = $deletable()->delete();

        // ── 3. Was uebrig bleibt, bleibt sichtbar ───────────────────────
        $left = (clone $orphans())->count();

        Log::info('Verwaiste Einheiten aufgeraeumt', [
            'vorher'       => $before,
            'angehaengt'   => $adopted,
            'geloescht'    => $removed,
            'bleibt_offen' => $left,
        ]);

        if ($left > 0) {
            Log::warning(
                "{$left} Einheit(en) ohne Plan haben Inhalt und keine Event-Zuordnung — "
                . 'bewusst stehengelassen, das entscheidet ein Mensch.'
            );
        }
    }

    /**
     * Die Verknuepfung geht zurueck, die geloeschten Zeilen nicht.
     *
     * Ein `down()`, das behauptet, es koenne eine Loeschung ruecknehmen, waere
     * eine Luege. Die angehaengten Einheiten wieder zu loesen ist ausserdem
     * nicht moeglich, ohne zu wissen, welche vorher verwaist waren — und
     * genau diese Information hat die Migration verbraucht. Rueckwaerts ist
     * hier also absichtlich ein Nichts: der Zustand davor war der Defekt.
     */
    public function down(): void
    {
        // Bewusst leer. Siehe Kommentar.
    }
};
