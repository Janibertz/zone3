<?php

namespace App\Console\Commands;

use App\Models\TrainingSession;
use App\Services\Run\SessionStructure;
use Illuminate\Console\Command;

/**
 * Den Ablauf geplanter Läufe einmal aus dem Katalog neu bauen.
 *
 * Bis 29.10.2026 erfand ein KI-Aufruf die Abschnitte beim ersten Öffnen
 * der Details, und sie passten oft nicht zu Dauer und Distanz der Einheit.
 * Seitdem baut sie {@see \App\Services\Run\RunWorkouts}. Dieser Befehl zieht
 * den Bestand nach — ohne Modellaufruf, also kostenlos.
 *
 * Gepinnte Einheiten bleiben stehen: dahinter steckt eine Entscheidung des
 * Athleten, oft ein geteiltes Workout mit eigener Struktur, und die lässt
 * sich an der Zeile nicht von einer Coach-Änderung unterscheiden.
 */
class RebuildSessionSteps extends Command
{
    protected $signature = 'sessions:rebuild-steps
        {--user=   : Nur dieser Athlet}
        {--dry-run : Nur anzeigen, nichts speichern}';

    protected $description = 'Baut den Ablauf geplanter Laeufe aus dem Katalog neu (ohne KI).';

    public function handle(SessionStructure $structure): int
    {
        $query = TrainingSession::where('status', 'planned')
            ->whereDate('planned_date', '>=', now()->toDateString())
            ->whereNull('pinned_at')
            ->whereNull('sport_type')
            ->with('user.runnerProfile');

        if ($userId = $this->option('user')) {
            $query->where('user_id', $userId);
        }

        $done = 0;

        foreach ($query->orderBy('planned_date')->get() as $session) {
            if (! $structure->supports($session)) {
                continue;
            }

            $before = sprintf('%s km / %d min', $session->distance_km, $session->duration_min);

            $session->steps = null;
            if (! $structure->apply($session)) {
                continue;
            }

            $this->line(sprintf(
                '#%d  %s  %-14s %s → %s km / %d min  (%s)',
                $session->id,
                $session->planned_date->format('Y-m-d'),
                $session->type,
                $before,
                $session->distance_km,
                $session->duration_min,
                $session->workout_variant,
            ));

            if (! $this->option('dry-run')) {
                $session->save();
            }
            $done++;
        }

        $this->info(($this->option('dry-run') ? 'Würde neu bauen: ' : 'Neu gebaut: ') . $done);

        return self::SUCCESS;
    }
}
