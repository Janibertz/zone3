<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Coros\CorosWorkoutSync;
use Illuminate\Console\Command;
use Throwable;

/**
 * Das Netz unter dem COROS-Abgleich: der neue Tag rueckt ins Fenster, eine
 * Massen-Aenderung lief am Beobachter vorbei. Ohne Aenderung kein Aufruf.
 * Nebenbei haelt es die Tokens frisch (`accessToken()` erneuert vor Ablauf).
 */
class SyncCorosWorkouts extends Command
{
    protected $signature = 'coros:sync-workouts {--user= : nur dieser Athlet}';

    protected $description = 'Haelt heute und morgen im COROS-Kalender aktuell (MCP)';

    public function handle(CorosWorkoutSync $sync): int
    {
        $users = User::whereHas('corosConnection')
            ->when($this->option('user'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($users as $user) {
            try {
                $this->line("{$user->id}: " . json_encode($sync->sync($user)));
            } catch (Throwable $e) {
                report($e);
                $this->warn("{$user->id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
