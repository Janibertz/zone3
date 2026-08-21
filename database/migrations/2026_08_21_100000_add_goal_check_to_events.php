<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Merker für die wöchentliche Zielprüfung.
 *
 * Dasselbe Muster wie `runner_profiles.week_check_week`: eine Kalenderwoche
 * als Schlüssel. Steht die aktuelle Woche drin, wurde in dieser Woche schon
 * gefragt und beantwortet — die Karte bleibt weg, der Push auch.
 *
 * `goal_confirmed_at` trennt „noch nicht gefragt" von „gefragt und
 * ausdrücklich beim Ziel geblieben". Wer sich einmal entschieden hat, soll
 * nicht jeden Sonntag dieselbe Frage bekommen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('goal_check_week', 8)->nullable()->after('priority');
            $table->timestamp('goal_confirmed_at')->nullable()->after('goal_check_week');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['goal_check_week', 'goal_confirmed_at']);
        });
    }
};
