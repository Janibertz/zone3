<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Polar\PolarActivityImporter;
use App\Services\Polar\PolarHealthImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Netz unter dem Polar-Webhook: Trainings alle 30 Minuten, Erholungswerte
 * stündlich (`--health`). Der Webhook ist der schnelle Weg; fällt er aus,
 * kommt ein Training hiermit höchstens eine halbe Stunde später.
 */
class SyncPolar extends Command
{
    protected $signature = 'polar:sync {--health : Schlaf, Nightly Recharge und VO2max statt Trainings} {--user= : nur dieser Athlet}';

    protected $description = 'Holt Trainings bzw. Erholungswerte von Polar AccessLink';

    public function handle(PolarActivityImporter $activities, PolarHealthImporter $health): int
    {
        $users = User::whereHas('polarConnection', fn ($q) => $q->whereNotNull('ai_consent_at'))
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

        return self::SUCCESS;
    }
}
