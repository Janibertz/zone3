<?php

namespace App\Console\Commands;

use App\Models\GarminDailyMetric;
use App\Models\User;
use App\Services\Readiness\ReadinessProposal;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sobald die Werte der Nacht da sind: bei niedriger Readiness einen
 * leichteren Vorschlag zur heutigen Einheit anlegen (seit 18.11.2026).
 */
class CheckReadiness extends Command
{
    protected $signature = 'readiness:check {--user= : nur dieser Athlet}';

    protected $description = 'Bei niedriger Readiness einen Ruhetag oder lockeren Lauf vorschlagen';

    public function handle(ReadinessProposal $proposal): int
    {
        $ids = GarminDailyMetric::whereDate('date', now()->toDateString())
            ->when($this->option('user'), fn ($q, $id) => $q->where('user_id', $id))
            ->pluck('user_id')
            ->unique();

        $made = 0;
        foreach (User::whereIn('id', $ids)->get() as $user) {
            try {
                $made += $proposal->check($user) ? 1 : 0;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->info("{$made} Vorschläge angelegt.");

        return self::SUCCESS;
    }
}
