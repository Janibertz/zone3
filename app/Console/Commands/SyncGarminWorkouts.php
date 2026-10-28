<?php

namespace App\Console\Commands;

use App\Models\GarminConnection;
use App\Models\User;
use App\Services\Garmin\GarminWorkoutSync;
use Illuminate\Console\Command;
use Throwable;

/**
 * Das Netz unter dem Abgleich: was ein Beobachter verpasst (eine Einheit
 * per Massen-Update geloescht, ein neuer Tag rueckt ins Fenster), holt der
 * stuendliche Lauf nach. Ohne Aenderung kostet er keinen Aufruf bei Garmin.
 */
class SyncGarminWorkouts extends Command
{
    protected $signature = 'garmin:sync-workouts {--user= : nur dieser Athlet}';

    protected $description = 'Haelt die naechsten Plantage im Garmin-Kalender aktuell (Training API)';

    public function handle(GarminWorkoutSync $sync): int
    {
        $users = User::whereHas('garminConnection')
            ->when($this->option('user'), fn ($q, $id) => $q->whereKey($id))
            ->get()
            ->filter(fn (User $u) => $u->garminConnection->allows(GarminConnection::WORKOUTS));

        foreach ($users as $user) {
            try {
                $result = $sync->sync($user);
                $this->line("{$user->id}: " . json_encode($result));
            } catch (Throwable $e) {
                // Ein Athlet mit kaputter Verbindung haelt die anderen nicht auf.
                report($e);
                $this->warn("{$user->id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
