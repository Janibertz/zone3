<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Coros\CorosActivityImporter;
use App\Services\Coros\CorosHealthImporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Erster Abruf direkt nach der Einwilligung: Erholungswerte (30 Tage) und
 * Aktivitaeten (42 Tage, in Portionen — der Rest folgt mit `coros:sync-activities`).
 */
class SyncCorosHealthJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(CorosHealthImporter $health, CorosActivityImporter $activities): void
    {
        $user = User::find($this->userId);

        if ($user) {
            $health->sync($user);
            $activities->sync($user);
        }
    }
}
