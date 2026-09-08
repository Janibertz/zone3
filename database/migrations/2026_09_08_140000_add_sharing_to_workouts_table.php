<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workouts teilen.
 *
 * Der Athlet baut ohnehin schon eigene Einheiten. Sie freizugeben kostet
 * einen Schalter — und es erspart eine zweite Tabelle mit kuratierten
 * Vorlagen daneben, die dasselbe waere und irgendwann etwas anderes sagen
 * wuerde.
 *
 * Dass das ueberhaupt geht, liegt an einer Entscheidung im Builder: seine
 * Bloecke tragen `pace_zone`, keine festen Paces. Ein geteiltes Workout
 * rechnet sich damit fuer jeden Laeufer aus seiner eigenen Schwellenpace
 * aus, ohne Umrechnung.
 *
 * `is_public` setzt der Ersteller. Ein Admin kann es nur ZURUECKnehmen —
 * das Workout verschwindet dann aus der Auswahl der anderen, bleibt seinem
 * Ersteller aber erhalten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workouts', function (Blueprint $table) {
            $table->boolean('is_public')->default(false)->after('tags');
            $table->timestamp('published_at')->nullable()->after('is_public');

            // Warum ein Admin die Freigabe zurueckgenommen hat. Ohne Grund
            // steht der Ersteller vor einem Workout, das ohne Erklaerung
            // verschwunden ist.
            $table->string('unpublished_reason')->nullable()->after('published_at');

            $table->index(['is_public', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('workouts', function (Blueprint $table) {
            $table->dropIndex(['is_public', 'type']);
            $table->dropColumn(['is_public', 'published_at', 'unpublished_reason']);
        });
    }
};
