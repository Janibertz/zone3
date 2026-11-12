<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Einwilligung in die Übermittlung an OpenAI (App Store, Guideline
 * 5.1.2(i), Jan 12.11.2026). Bis dahin gab es sie nur an der Garmin-,
 * COROS- und Apple-Health-Verbindung; Plan und Coach liefen ohne.
 * Bestehende Konten bleiben leer und werden einmal gefragt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('ai_coach_consent_at')->nullable()->after('has_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ai_coach_consent_at');
        });
    }
};
