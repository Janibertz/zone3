<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geteilte Workouts melden (App Store, Guideline 1.2, Jan 12.11.2026).
 * Wer meldet, sieht das Workout nicht mehr; die Admins bekommen Push und
 * sehen die Meldungen unter /admin/workouts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['workout_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_reports');
    }
};
