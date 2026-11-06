<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Coros\CorosActivityImporter;
use Illuminate\Console\Command;
use Throwable;

/** Alle 15 Minuten: neue Aktivitaeten von COROS (MCP schickt nichts von sich aus). */
class SyncCorosActivities extends Command
{
    protected $signature = 'coros:sync-activities {--user= : nur dieser Athlet}';

    protected $description = 'Holt neue Aktivitaeten samt FIT-Datei von COROS (MCP)';

    public function handle(CorosActivityImporter $importer): int
    {
        $users = User::whereHas('corosConnection', fn ($q) => $q->whereNotNull('ai_consent_at'))
            ->when($this->option('user'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($users as $user) {
            try {
                $this->line("{$user->id}: " . json_encode($importer->sync($user)));
            } catch (Throwable $e) {
                report($e);
                $this->warn("{$user->id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
