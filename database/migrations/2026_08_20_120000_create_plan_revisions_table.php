<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Verlauf der Planänderungen.
 *
 * Jede Neuberechnung löscht den alten Plan und legt einen neuen an. Was
 * vorher dort stand, war danach nirgends mehr nachzulesen — der Athlet sah
 * am Dienstag einen anderen Plan als am Montag und hatte keine Möglichkeit
 * herauszufinden, was sich geändert hatte oder warum. Der Bericht des
 * Validators lag zwar in `training_plans.context`, aber auch der ging mit
 * dem Plan unter und wurde nie angezeigt.
 *
 * Diese Tabelle überlebt die Pläne: sie hängt am Event, nicht am Plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            // Der Plan, der aus dieser Revision hervorging. Wird null, sobald
            // die naechste Neuberechnung ihn ersetzt — der Eintrag bleibt.
            $table->foreignId('training_plan_id')->nullable()->constrained()->nullOnDelete();

            // Was die Neuberechnung ausgeloest hat: 'initial', 'auto', 'user'.
            $table->string('triggered_by', 20)->default('auto');

            // Die Aenderungen gegenueber dem vorherigen Plan, je Tag eine
            // Zeile, und der Bericht des Validators.
            $table->json('changes')->nullable();
            $table->json('corrections')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_revisions');
    }
};
