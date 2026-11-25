<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messpunkte einer Aktivität alle 2 s (Jan, 25.11.2026) für die
 * Verlaufskurve und das Soll/Ist ohne passende Runden. Eigene Tabelle,
 * damit keine Liste von Aktivitäten die Reihe mitlädt. Keine GPS-Punkte —
 * die Strecke steht als Linie an der Aktivität.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_streams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('source', 20);
            $table->unsignedTinyInteger('interval_s')->default(2);
            $table->unsignedInteger('points')->default(0);
            $table->json('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_streams');
    }
};
