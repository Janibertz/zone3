<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Welche Sportart tatsächlich stattgefunden hat.
 *
 * Der Strava-Import kannte nur zwei Kategorien: Kraft und "alles andere".
 * Alles andere wurde als `easy_run` gespeichert — auch Schwimmen, Radfahren
 * und Yoga. Der Coach las danach den Trainingstyp, sah "Lockerer Lauf" und
 * fragte den Athleten nach seinem Lauf, obwohl der geschwommen war.
 *
 * Die Sportart stand die ganze Zeit korrekt in `activities.type`. Sie wurde
 * nur nie an die Einheit weitergereicht.
 *
 * NULL bedeutet "Laufen" — das ist der Normalfall und deckt jede Einheit ab,
 * die aus dem Plan stammt statt aus einem Import.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->string('sport_type', 32)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropColumn('sport_type');
        });
    }
};
