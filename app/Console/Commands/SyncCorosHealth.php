<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Coros\CorosHealthImporter;
use Illuminate\Console\Command;
use Throwable;

/** Stuendlich: Erholungswerte von COROS (MCP schickt nichts von sich aus). */
class SyncCorosHealth extends Command
{
    protected $signature = 'coros:sync-health {--user= : nur dieser Athlet} {--days= : Zeitraum statt der Vorgabe}';

    protected $description = 'Holt Schlaf, HRV, Ruhepuls und Co. von COROS (MCP)';

    public function handle(CorosHealthImporter $importer): int
    {
        $users = User::whereHas('corosConnection', fn ($q) => $q->whereNotNull('ai_consent_at'))
            ->when($this->option('user'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($users as $user) {
            try {
                $days = $this->option('days') ? (int) $this->option('days') : null;
                $this->line("{$user->id}: " . json_encode($importer->sync($user, $days)));
            } catch (Throwable $e) {
                report($e);
                $this->warn("{$user->id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
