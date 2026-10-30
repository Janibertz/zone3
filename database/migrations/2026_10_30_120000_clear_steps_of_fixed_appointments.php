<?php

use App\Models\TrainingSession;
use Illuminate\Database\Migrations\Migration;

/**
 * Am festen Termin (Laufclub) baute das Öffnen der Details einen Ablauf aus
 * dem Katalog. Den Inhalt gibt aber der Trainer vor, jede Woche einen
 * anderen (Jan, 30.10.2026). Geplante Termine verlieren ihn wieder.
 */
return new class extends Migration
{
    public function up(): void
    {
        TrainingSession::with(['user.runnerProfile', 'trainingPlan'])
            ->where('status', 'planned')
            ->whereNotNull('steps')
            ->whereNull('sport_type')
            ->get()
            ->filter(fn (TrainingSession $s) => $s->isFixedAppointment())
            ->each(fn (TrainingSession $s) => $s->forceFill(['steps' => null, 'workout_variant' => null])->saveQuietly());
    }

    public function down(): void
    {
        // Ein gelöschter Ablauf lässt sich nicht zurückholen — und soll es auch nicht.
    }
};
