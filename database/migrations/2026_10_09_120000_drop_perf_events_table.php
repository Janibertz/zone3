<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Anfrage-Messung wird abgebaut.
 *
 * `perf_events` sammelte eine Zeile pro Anfrage, pro Job, pro langsamer
 * Query und pro ausgehendem Aufruf. In einem Monat Betrieb kamen 51 Zeilen
 * zusammen, und keine davon hat eine Frage beantwortet, die nicht auch im
 * Log stand — bei zwei aktiven Athleten misst man keine Lastprobleme.
 *
 * Was bleibt, ist `perf_command_runs`: eine Zeile je Hintergrund-Kommando,
 * fortgeschrieben. Das ist die Achse, auf der Zone3 wirklich kaputtging
 * (sechs Tage ohne Strava-Import), und die Prueffrage dazu steht in
 * `Watchdog::overdueCommands()`.
 *
 * `last_query_count` faellt mit, weil der Zaehler dahinter ein `DB::listen`
 * auf JEDER Query war — ein Preis, der nur fuer eine Informationsspalte
 * gezahlt wurde. `isOverdue()` hat ihn nie gelesen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('perf_events');

        if (Schema::hasColumn('perf_command_runs', 'last_query_count')) {
            Schema::table('perf_command_runs', function (Blueprint $table) {
                $table->dropColumn('last_query_count');
            });
        }
    }

    /**
     * Zurueck geht nur die Form, nicht der Inhalt.
     *
     * Die Spalten entsprechen der urspruenglichen Anlage in
     * `2026_09_09_100000_create_perf_tables.php`, damit ein `migrate:rollback`
     * nicht an einer fehlenden Tabelle scheitert. Die verworfenen Messwerte
     * kommen nicht zurueck — sie waren der Grund fuer diese Migration.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('perf_command_runs', 'last_query_count')) {
            Schema::table('perf_command_runs', function (Blueprint $table) {
                $table->unsignedInteger('last_query_count')->nullable();
            });
        }

        if (Schema::hasTable('perf_events')) {
            return;
        }

        Schema::create('perf_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16)->index();
            $table->string('name', 191);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('status', 16)->nullable();
            $table->unsignedInteger('query_count')->nullable();
            $table->unsignedInteger('query_ms')->nullable();
            $table->unsignedInteger('memory_kb')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }
};
