<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\TrainingPlan;
use Illuminate\Console\Command;

/**
 * Plan ohne Rennen: ist ein Block zu Ende, beginnt der nächste von selbst
 * (Jan, 10.11.2026). `event_date` rückt um zwölf Wochen weiter, danach
 * sieht `plan:auto-update` die Lücke im Fenster und schreibt die neue Woche.
 *
 * Nur Ziele mit aktivem Plan rollen — ein beendetes oder an ein Rennen
 * übergebenes Ziel bleibt stehen.
 */
class RollOpenBlocks extends Command
{
    protected $signature = 'plan:roll-blocks {--user=* : Nur diese Nutzer-IDs}';

    protected $description = 'Startet bei Zielen ohne Rennen den nächsten Block, wenn der laufende vorbei ist.';

    public function handle(): int
    {
        $rolled = self::rollAll($this->option('user') ?: []);
        $this->line("{$rolled} Block/Blöcke weitergerückt.");

        return self::SUCCESS;
    }

    /** @param  array<int>  $userIds */
    public static function rollAll(array $userIds = []): int
    {
        $query = Event::where('goal_type', 'open')
            ->whereDate('event_date', '<', now()->toDateString())
            ->whereIn('id', TrainingPlan::where('is_active', true)->select('event_id'));

        if ($userIds !== []) {
            $query->whereIn('user_id', $userIds);
        }

        $rolled = 0;
        foreach ($query->get() as $event) {
            if ($event->rollOpenBlock()) {
                $rolled++;
            }
        }

        return $rolled;
    }
}
