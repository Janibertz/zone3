<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Das Gespraech vor dem naechsten Block.
 *
 * Gewuenscht: „Vielleicht wäre ein kurzes Interview nicht schlecht damit sich
 * der Plan perfekt einstellen kann."
 *
 * Der Reiz liegt darin, was NICHT gefragt wird. Zone3 kennt 132 Aktivitaeten,
 * 150 Wellbeing-Eintraege, 95 Garmin-Tage und 128 bewertete Einheiten — wer
 * danach noch nach dem Wochenumfang fragt, baut eine zweite Wahrheit neben
 * die Daten. Gefragt wird nur, was die Datenbank nicht hergibt:
 *
 *  1. Wie das letzte Rennen WIRKLICH lief. Von acht vergangenen Rennen hatte
 *     keines ein Ergebnis — und `PlanContextBuilder::pastPlanResults()` holt
 *     genau das, filtert aber auf `overall_rating` und lief deshalb seit
 *     jeher leer. Der Mechanismus war da, nur nie gefuettert.
 *  2. WARUM Einheiten ausfielen. Die App sieht `skipped`, nie den Grund — und
 *     der entscheidet, ob der naechste Block weniger Umfang oder eine andere
 *     Struktur braucht.
 *  3. Was als Naechstes kommt und was sich im Leben geaendert hat.
 *
 * Die Antworten sind keine Prompt-Dekoration: `PlanInterviewService::apply()`
 * schreibt sie dorthin, wo sie wirken — ins Wochenraster, in den Umfangsdeckel,
 * in die Rennbilanz. Ein Interview, das nur den Prompt fuettert, waere genau
 * die doppelte Wahrheit, die dieses Projekt schon dreimal repariert hat: das
 * Geruest ist bindend, und es liest keine Freitexte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Das Rennen, auf das der neue Block zulaeuft. Nullable: ein Block
            // ohne Rennen ist eine legitime Antwort (Grundlage, Formaufbau).
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();

            // ── Rueckblick ───────────────────────────────────────────────
            $table->foreignId('reviewed_event_id')->nullable()->constrained('events')->nullOnDelete();

            // Die gelaufene Zeit in Minuten. Eine Zahl, kein „3:38" — damit
            // sich Ziel und Ergebnis vergleichen lassen.
            $table->unsignedInteger('actual_minutes')->nullable();

            // 1–5, wie der ganze Block sich angefuehlt hat. Fuellt
            // `training_plans.overall_rating` und damit `pastPlanResults`.
            $table->unsignedTinyInteger('block_rating')->nullable();
            $table->text('block_note')->nullable();

            // ── Warum Einheiten ausfielen ────────────────────────────────
            // time | body | motivation | fit | weather | none
            $table->string('skip_reason', 20)->nullable();
            $table->text('skip_note')->nullable();

            // ── Was sich geaendert hat ───────────────────────────────────
            // Tage pro Woche und Minuten je Tag. Beides geht direkt ins
            // Wochenraster — der staerkste Hebel aufs Geruest.
            $table->unsignedTinyInteger('days_per_week')->nullable();
            $table->unsignedSmallInteger('minutes_per_day')->nullable();
            $table->text('changes_note')->nullable();

            // ── Ausrichtung ──────────────────────────────────────────────
            // race | base | comeback | maintain
            $table->string('focus', 20)->nullable();

            // Alles, wofuer es kein Feld gibt. Geht als Text an den Coach —
            // bewusst NUR das, was sich nicht strukturieren laesst.
            $table->text('free_note')->nullable();

            // Solange null, ist das Interview ein Entwurf. Erst der Abschluss
            // schreibt die Folgen.
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('applied_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_interviews');
    }
};
