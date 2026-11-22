<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ein Protokoll je Nachricht an den Coach (Stufe 1 des neuen Coaches,
 * 22.11.2026): welcher Weg, wie viele Schritte, welche Werkzeuge, ob
 * nachgehakt wurde, was es gekostet hat. Kein Text — der steht in
 * `coach_messages` bzw. 30 Tage in `ai_logs`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_turns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained('coach_messages')->nullOnDelete();
            $table->foreignId('reply_id')->nullable()->constrained('coach_messages')->nullOnDelete();
            $table->string('channel', 20)->nullable();
            $table->string('engine', 20);
            $table->string('model', 60)->nullable();
            $table->unsignedSmallInteger('steps')->default(0);
            $table->json('tools')->nullable();
            $table->unsignedSmallInteger('changes')->default(0);
            $table->json('nudges')->nullable();
            $table->boolean('flagged')->default(false);
            $table->boolean('fallback')->default(false);
            $table->decimal('cost_eur', 10, 6)->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_turns');
    }
};
