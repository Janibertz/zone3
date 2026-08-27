<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indizes für die Abfragen, die bei jedem Seitenaufruf laufen.
 *
 * Nachgezählt statt vermutet — jeder dieser Indizes deckt eine Abfrageform
 * ab, die im Code mehrfach vorkommt:
 *
 *   training_sessions (user_id, planned_date)
 *       Vierzehn Stellen kombinieren genau diese beiden Spalten: die
 *       Zuordnung einer importierten Aktivität zum Tag, die Einheit von
 *       heute auf dem Dashboard, der Wochenblock, die Kalenderseite.
 *       Vorhanden waren (training_plan_id, planned_date) und
 *       (user_id, status) — keiner davon hilft hier.
 *
 *   training_sessions (user_id, type, status)
 *       Der Vergleich im Coach-Review: derselbe Einheitentyp der letzten
 *       Wochen, nur abgeschlossene. Läuft nach jeder Einheit.
 *
 *   coach_messages (user_id, created_at)
 *       Der Chat lädt die letzten fünfzig Nachrichten absteigend. Ohne den
 *       Index sortiert die Datenbank die gesamte Historie des Athleten,
 *       um fünfzig Zeilen zurückzugeben.
 *
 *   wellbeing_entries (user_id, date)
 *       Siebzehn Stellen filtern erst auf den Athleten und dann auf einen
 *       Tag oder Zeitraum. Beide Spalten hatten je einen eigenen Index —
 *       MySQL kann davon nur einen nutzen.
 *
 * Nicht dabei: activity_id und strava_id. Beide sind längst indiziert —
 * activity_id über seinen Fremdschlüssel, strava_id über seine
 * Unique-Bedingung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->index(['user_id', 'planned_date'], 'ts_user_date_idx');
            $table->index(['user_id', 'type', 'status'], 'ts_user_type_status_idx');
        });

        Schema::table('coach_messages', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'cm_user_created_idx');
        });

        Schema::table('wellbeing_entries', function (Blueprint $table) {
            $table->index(['user_id', 'date'], 'we_user_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropIndex('ts_user_date_idx');
            $table->dropIndex('ts_user_type_status_idx');
        });

        Schema::table('coach_messages', function (Blueprint $table) {
            $table->dropIndex('cm_user_created_idx');
        });

        Schema::table('wellbeing_entries', function (Blueprint $table) {
            $table->dropIndex('we_user_date_idx');
        });
    }
};
