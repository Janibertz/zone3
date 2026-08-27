<?php

namespace App\Console\Commands;

use App\Jobs\RegeneratePlanJob;
use App\Models\TrainingPlan;
use Illuminate\Console\Command;

/**
 * Sonntagabend: die kommende Woche schreiben.
 *
 * Bis hierher gab es keinen verabredeten Zeitpunkt, zu dem der Plan
 * entsteht. Er änderte sich, wenn irgendetwas ihn anstiess — ein Import,
 * eine abgehakte Einheit, eine Lücke im Fenster. Für den Athleten hiess
 * das: er wusste nie, wann sein Plan feststeht und ab wann er sich darauf
 * verlassen kann.
 *
 * Jetzt gibt es diesen Zeitpunkt. Sonntags um 18:00 fragt {@see SendWeekCheckReminders}
 * nach der Verfügbarkeit, eine Stunde später wird die Woche geschrieben —
 * mit der Antwort, wenn eine kam, und sonst mit dem Wochenraster aus dem
 * Profil. Danach steht die Woche, bis der Athlet selbst etwas ändert.
 *
 * Der Lauf ist billig: {@see \App\Services\PlanDeltaService} vergleicht das
 * Gerüst mit dem, was schon dasteht, und wenn sich nichts geändert hat,
 * wird das Sprachmodell gar nicht erst gefragt.
 */
class WriteWeeklyPlans extends Command
{
    protected $signature = 'plan:write-week
        {--user=* : Nur diese Nutzer-IDs}
        {--force  : Auch ausserhalb des Wochenwechsels laufen}';

    protected $description = 'Schreibt die kommende Trainingswoche für alle Athleten mit aktivem Plan.';

    public function handle(): int
    {
        // Sonntag und Montag — dasselbe Fenster, in dem auch die
        // Verfügbarkeitsabfrage ansteht. Wer den Sonntag verpasst, bekommt
        // seine Woche am Montag früh.
        $today = now();

        if (! $this->option('force') && ! $today->isSunday() && ! $today->isMonday()) {
            $this->line('Kein Wochenwechsel — nichts zu tun.');

            return self::SUCCESS;
        }

        $userIds = $this->option('user');

        $query = TrainingPlan::where('is_active', true)
            ->whereHas('event', fn ($q) => $q->where('event_date', '>=', $today->toDateString()))
            ->with('event');

        if (! empty($userIds)) {
            $query->whereIn('user_id', $userIds);
        }

        $queued = 0;

        $query->chunk(50, function ($plans) use (&$queued) {
            foreach ($plans as $plan) {
                if (! $plan->event) {
                    continue;
                }

                $plan->update(['needs_plan_update' => true]);
                RegeneratePlanJob::dispatch($plan->user_id, RegeneratePlanJob::REASON_WEEKLY);

                $this->line("↻ Woche geschrieben für Nutzer #{$plan->user_id} ({$plan->event->name})");
                $queued++;
            }
        });

        $this->info("Wochenplanung: {$queued} Pläne vorgemerkt.");

        return self::SUCCESS;
    }
}
