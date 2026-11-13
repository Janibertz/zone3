<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\WhoopWebhookEvent;
use App\Services\Whoop\WhoopActivityImporter;
use App\Services\Whoop\WhoopHealthImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Netz unter dem WHOOP-Webhook: Trainings alle 30 Minuten, Erholungswerte
 * stündlich (`--health`). Hält nebenbei die Tokens frisch — ein
 * Refresh-Token, der nie benutzt wird, hilft niemandem.
 */
class SyncWhoop extends Command
{
    protected $signature = 'whoop:sync {--health : Schlaf und Recovery statt Trainings} {--user= : nur dieser Athlet}';

    protected $description = 'Holt Trainings bzw. Erholungswerte von WHOOP';

    public function handle(WhoopActivityImporter $activities, WhoopHealthImporter $health): int
    {
        $users = User::whereHas('whoopConnection', fn ($q) => $q->whereNotNull('ai_consent_at'))
            ->when($this->option('user'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($users as $user) {
            try {
                $result = $this->option('health') ? $health->sync($user) : $activities->sync($user);
                $this->line("{$user->id}: " . json_encode($result));
            } catch (Throwable $e) {
                report($e);
                $this->warn("{$user->id}: {$e->getMessage()}");
            }
        }

        if ($this->option('health')) {
            WhoopWebhookEvent::where('created_at', '<', now()->subDays(30))->delete();
        }

        return self::SUCCESS;
    }
}
