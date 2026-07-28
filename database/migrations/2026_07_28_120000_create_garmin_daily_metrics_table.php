<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('garmin_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            // All nullable — a missing Garmin value is "keine Daten", never 0.
            $table->float('hrv')->nullable();                 // ms, last night avg
            $table->unsignedSmallInteger('resting_hr')->nullable();
            $table->float('sleep_hours')->nullable();
            $table->unsignedSmallInteger('sleep_score')->nullable();
            $table->unsignedSmallInteger('body_battery_low')->nullable();
            $table->unsignedSmallInteger('body_battery_high')->nullable();
            $table->unsignedSmallInteger('stress_avg')->nullable();
            $table->unsignedInteger('steps')->nullable();
            $table->unsignedSmallInteger('training_readiness')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garmin_daily_metrics');
    }
};
