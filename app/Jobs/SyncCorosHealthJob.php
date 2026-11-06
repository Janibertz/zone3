<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Coros\CorosHealthImporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Erster Abruf der Erholungswerte direkt nach der Einwilligung (30 Tage zurueck). */
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

    public function handle(CorosHealthImporter $importer): void
    {
        $user = User::find($this->userId);

        if ($user) {
            $importer->sync($user);
        }
    }
}
