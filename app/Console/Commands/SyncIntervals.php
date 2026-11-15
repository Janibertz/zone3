<?php

namespace App\Console\Commands;

use App\Models\IntervalsWebhookEvent;
use App\Models\User;
use App\Services\Intervals\IntervalsActivityImporter;
use App\Services\Intervals\IntervalsWorkoutSync;
use Illuminate\Console\Command;
use Throwable;

/**
 * intervals.icu: stündlich der Kalender (ein unveränderter Plan kostet keinen
 * Aufruf), mit `--activities` alle zwei Stunden das Netz unter dem Webhook.
 * Das Kontingent ist knapp (100 Aufrufe je Athlet und Tag), darum nicht öfter.
 */
class SyncIntervals extends Command
{
    protected $signature = 'intervals:sync {--activities : Zwift-Fahrten statt Kalender} {--user= : nur dieser Athlet}';

    protected $description = 'Gleicht Radeinheiten mit dem intervals.icu-Kalender ab bzw. holt Zwift-Fahrten';

    public function handle(IntervalsWorkoutSync $workouts, IntervalsActivityImporter $activities): int
    {
        $users = User::whereHas('intervalsConnection')
            ->when($this->option('user'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($users as $user) {
            try {
                $result = $this->option('activities') ? $activities->sync($user) : $workouts->sync($user);
                $this->line("{$user->id}: " . json_encode($result));
            } catch (Throwable $e) {
                report($e);
                $this->warn("{$user->id}: {$e->getMessage()}");
            }
        }

        if ($this->option('activities')) {
            IntervalsWebhookEvent::where('created_at', '<', now()->subDays(30))->delete();
        }

        return self::SUCCESS;
    }
}
