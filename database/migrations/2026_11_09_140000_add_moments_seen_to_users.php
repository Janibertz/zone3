<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Welche Momente der Athlet schon gesehen hat (seit 09.11.2026): „Woche
 * geschafft“ merkt sich den Montag der gefeierten Woche, „Rennen gelaufen“
 * das Rennen. So erscheint jeder Moment genau einmal, auf jedem Gerät.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('moment_week_seen')->nullable();
            $table->unsignedBigInteger('moment_race_seen')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['moment_week_seen', 'moment_race_seen']);
        });
    }
};
