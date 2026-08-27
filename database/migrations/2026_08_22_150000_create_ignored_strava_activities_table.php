<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aktivitäten, die der Athlet gelöscht hat.
 *
 * Ein reines Löschen genügt nicht. Der manuelle Strava-Abgleich läuft über
 * `Activity::updateOrCreate` und legt jede Aktivität wieder an, die Strava
 * noch kennt — die gelöschte wäre beim nächsten Abgleich zurück, und der
 * Athlet hätte keine Möglichkeit zu verstehen, warum.
 *
 * Der Webhook allein hätte sie nicht zurückgeholt: er reagiert nur auf
 * `aspect_type = create`. Der Abgleich schon.
 *
 * Deshalb ein Grabstein je Strava-ID. Er überlebt das Löschen der Aktivität
 * und wird beim Abgleich geprüft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ignored_strava_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('strava_id');
            $table->timestamps();

            // Je Athlet und Aktivität nur ein Eintrag; zugleich der Index,
            // über den der Abgleich prüft.
            $table->unique(['user_id', 'strava_id'], 'ignored_user_strava_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ignored_strava_activities');
    }
};
