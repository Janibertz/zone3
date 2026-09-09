<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messwerte statt Vermutungen.
 *
 * Der Admin-Bereich beantwortet inzwischen „laeuft die Maschine" — Queues,
 * Failed Jobs, Plan-Luecken. Was er nicht beantwortet: „warum ist die Seite
 * langsam" und „ist die Hintergrundarbeit ueberhaupt gelaufen". Beides hat
 * schon Tage gekostet: sechs Tage ohne Strava-Import, gemerkt vom Athleten
 * selbst.
 *
 * Aufgezeichnet wird nach dem Prinzip Zusammenfassung + Ausreisser. Eine
 * Zeile pro Anfrage mit Dauer und Query-Anzahl; einzelne Queries nur, wenn
 * sie langsam sind. Der Grund ist nicht Sparsamkeit, sondern der Webprozess:
 * `php artisan serve` ist single-threaded, und eine Messung, die vierzig
 * Zeilen pro Seitenaufruf schreibt, macht genau das langsamer, was sie
 * beobachten soll.
 *
 * Geschrieben wird deshalb EINMAL, im terminate-Callback, nachdem die
 * Antwort erzeugt ist.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * Ein Ereignis: eine Anfrage, eine langsame Query, ein Job, ein
         * ausgehender Aufruf. Eine Tabelle statt vier, weil die Formen
         * gleich sind und das Aufraeumen dann auch eine Sache ist.
         */
        Schema::create('perf_events', function (Blueprint $table) {
            $table->id();

            // request | query | job | http
            $table->string('type', 20);

            // Route-Muster, normalisiertes SQL, Job-Klasse, Host+Pfad.
            // Bewusst das MUSTER, nicht die konkrete URL: sonst gruppiert
            // sich nichts und jede Zeile ist ein Einzelfall.
            $table->string('name', 191);

            $table->unsignedInteger('duration_ms')->default(0);

            // HTTP-Status, „ok", „failed". Nullable, weil nicht jeder Typ
            // einen hat.
            $table->string('status', 20)->nullable();

            // Eigene Spalten statt JSON: darueber wird aggregiert, und
            // JSON-Extraktion ist unter SQLite und MySQL nicht dasselbe.
            $table->unsignedInteger('query_count')->nullable();
            $table->unsignedInteger('query_ms')->nullable();
            $table->unsignedInteger('memory_kb')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Alles, was nur im Einzelfall interessiert: Methode, volle URI,
            // erste Zeile einer Exception.
            $table->json('context')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['type', 'created_at']);
            $table->index('created_at');
        });

        /**
         * Kommandos werden NICHT angehaengt, sondern fortgeschrieben.
         *
         * `push:wellbeing-reminders` laeuft jede Minute — als Ereignisstrom
         * waeren das 43.000 Zeilen im Monat fuer eine Frage, die eine
         * einzige Zeile beantwortet: wann lief es zuletzt, wie lange, und
         * ging es gut.
         */
        Schema::create('perf_command_runs', function (Blueprint $table) {
            $table->id();
            $table->string('command', 191)->unique();
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('last_duration_ms')->nullable();
            $table->integer('last_exit_code')->nullable();
            $table->unsignedInteger('last_query_count')->nullable();
            $table->unsignedInteger('slowest_ms')->nullable();
            $table->unsignedBigInteger('runs_total')->default(0);
            $table->unsignedBigInteger('failures_total')->default(0);
            $table->timestamps();
        });

        /**
         * Wann zuletzt wegen welcher Sache Bescheid gesagt wurde.
         *
         * Ohne diese Tabelle schickt der Watchdog alle 15 Minuten dieselbe
         * Nachricht, und nach dem dritten Mal liest sie niemand mehr.
         */
        Schema::create('perf_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 191)->unique();
            $table->string('message', 500);
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perf_alerts');
        Schema::dropIfExists('perf_command_runs');
        Schema::dropIfExists('perf_events');
    }
};
