<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Polar\PolarActivityImporter;
use App\Services\Polar\PolarHealthImporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Erster Abruf direkt nach dem Verbinden: Erholungswerte (28 Tage) und Trainings. */
class SyncPolarJob implements ShouldQueue, ShouldBeUnique
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

    public function handle(PolarHealthImporter $health, PolarActivityImporter $activities): void
    {
        $user = User::find($this->userId);

        if ($user) {
            $health->sync($user);
            $activities->sync($user);
        }
    }
}
