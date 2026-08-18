<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merkt sich, fuer welche Kalenderwoche der Athlet seine Verfuegbarkeit
 * zuletzt bestaetigt hat. Ohne diesen Wert wuerde die Wochenabfrage jeden
 * Tag erneut auftauchen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table) {
            $table->string('week_check_week', 10)->nullable()->after('weekly_availability');
        });
    }

    public function down(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table) {
            $table->dropColumn('week_check_week');
        });
    }
};
