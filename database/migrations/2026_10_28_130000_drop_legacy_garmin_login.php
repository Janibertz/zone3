<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schritt 6 der Garmin-Integration: der alte Login ueber den Python-Fit-
 * Service (garth, Passwort im Formular) ist weg. Mit ihm gehen die
 * gespeicherten Zugangsdaten — ein verschluesselter, aber gueltiger Login
 * zu einem Garmin-Konto, den nichts mehr liest, ist nur noch ein Risiko.
 *
 * Training Readiness kam ausschliesslich ueber diesen Weg; die Health API
 * kennt sie nicht (siehe CLAUDE.md, Garmin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['garmin_email', 'garmin_session']);
        });

        Schema::table('garmin_daily_metrics', function (Blueprint $table) {
            $table->dropColumn('training_readiness');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('garmin_email')->nullable();
            $table->text('garmin_session')->nullable();
        });

        Schema::table('garmin_daily_metrics', function (Blueprint $table) {
            $table->unsignedSmallInteger('training_readiness')->nullable();
        });
    }
};
