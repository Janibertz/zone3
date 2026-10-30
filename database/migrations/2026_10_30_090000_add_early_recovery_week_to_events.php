<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eine vorgezogene Entlastungswoche (FatigueCheck) — der Montag der Woche.
 * Gespeichert statt jedes Mal neu berechnet: erholen sich HRV und Tagesform
 * mitten in der Woche, darf eine Neuberechnung die Entlastung nicht wieder
 * zurücknehmen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->date('early_recovery_week')->nullable()->after('plan_start_date');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('early_recovery_week');
        });
    }
};
