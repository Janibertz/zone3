<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Coach schlägt vor, der Athlet entscheidet.
 *
 * Bis hierher hat `AdjustPlanForWellbeingJob` die heutige Einheit direkt
 * überschrieben — Typ, Titel, Beschreibung, Distanz, Dauer, Pace, Zone und
 * Intensität. Wer sich schlapp fühlte und das eintrug, fand seinen
 * Schwellenlauf als zwanzig lockere Minuten wieder, ohne gefragt worden zu
 * sein. Die Anpassung mag fachlich richtig gewesen sein; die Entscheidung
 * gehört trotzdem dem Athleten.
 *
 * Eine Empfehlung hält beide Seiten fest: was geplant war und was der Coach
 * stattdessen vorschlägt. Erst beim Annehmen wird geschrieben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_recommendations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wellbeing_entry_id')->nullable()->constrained()->nullOnDelete();

            // Woher der Vorschlag kommt — heute nur `wellbeing`, spaeter auch
            // die Vorlagen, die der Athlet selbst aussucht.
            $table->string('source')->default('wellbeing');

            // Ein Satz, WARUM. Die Begruendung gehoert neben den Vorschlag,
            // nicht in die Beschreibung der Einheit — sonst steht sie nach
            // dem Annehmen dauerhaft im Plan.
            $table->text('reason')->nullable();

            // Was geplant war, und was daraus werden soll. Beides, damit die
            // Karte ein Vorher/Nachher zeigen kann und die Entscheidung
            // nachvollziehbar bleibt.
            $table->json('before');
            $table->json('after');

            // pending → accepted | rejected | expired
            $table->string('status')->default('pending');
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            // Die Abfrage des Dashboards: was liegt fuer diesen Athleten offen?
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_recommendations');
    }
};
