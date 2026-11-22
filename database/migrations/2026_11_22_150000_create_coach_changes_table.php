<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jede Planänderung des Coaches als ein Paket mit Vorher-Stand (Stufe 2 des
 * neuen Coaches, 22.11.2026) — daraus macht „Rückgängig“ den alten Plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('steps');
            $table->json('lines');
            // Einheiten, wie sie vorher waren (ganze Zeilen), und die, die
            // das Paket neu angelegt hat.
            $table->json('before');
            $table->json('created_ids');
            // Verfügbarkeit des aktiven Plans vorher (Ruhetag schaltet sie aus).
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->json('plan_overrides_before')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_changes');
    }
};
