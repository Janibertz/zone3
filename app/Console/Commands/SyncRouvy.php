<?php

namespace App\Console\Commands;

use App\Models\RouvyWebhookEvent;
use App\Models\User;
use App\Services\Rouvy\RouvyActivityImporter;
use App\Services\Rouvy\RouvyWorkoutSync;
use Illuminate\Console\Command;
use Throwable;

/**
 * ROUVY: stündlich die Workouts (ein unveränderter Plan kostet keinen
 * Aufruf), mit `--activities` alle zwei Stunden das Netz unter dem Webhook.
 * ROUVY erlaubt 60 Aufrufe pro Minute für die ganze App.
 */
class SyncRouvy extends Command
{
    protected $signature = 'rouvy:sync {--activities : Fahrten statt Workouts} {--user= : nur dieser Athlet}';

    protected $description = 'Gleicht Radeinheiten mit ROUVY ab bzw. holt ROUVY-Fahrten';

    public function handle(RouvyWorkoutSync $workouts, RouvyActivityImporter $activities): int
    {
        $users = User::whereHas('rouvyConnection')
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
            RouvyWebhookEvent::where('created_at', '<', now()->subDays(30))->delete();
        }

        return self::SUCCESS;
    }
}
