<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jeder Anruf von Strava, in der Datenbank.
 *
 * Die Frage „ruft Strava ueberhaupt an?" hat mehrere Sitzungen gekostet und
 * war nicht zu beantworten: Produktion loggt in den Container-Stream, nicht
 * in eine Datei, und die Log-Ansicht findet dort nichts. Eine Zeile je
 * Aufruf beantwortet sie endgueltig — und zwar unabhaengig davon, wie das
 * Logging konfiguriert ist.
 *
 * Geschrieben wird VOR jeder Filterung. Auch ein Aufruf, den der Handler
 * verwirft, ist die Information, dass ueberhaupt einer kam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strava_webhook_events', function (Blueprint $table) {
            $table->id();

            $table->string('object_type')->nullable();
            $table->string('aspect_type')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('object_id')->nullable();

            // Wem der Aufruf zugeordnet werden konnte — null heisst: keinem.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Was daraus wurde: imported, ignored_type, unknown_owner,
            // not_fetchable, tombstoned.
            $table->string('outcome')->nullable();
            $table->string('note')->nullable();

            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strava_webhook_events');
    }
};
