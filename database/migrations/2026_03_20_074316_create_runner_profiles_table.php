<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('runner_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('threshold_heart_rate')->nullable(); // LTHR
            $table->integer('max_heart_rate')->nullable(); // Max HR
            $table->float('threshold_speed')->nullable(); // Pace in minutes (e.g. 5.5 = 5:30)
            $table->json('heart_rate_zones')->nullable();
            $table->json('pace_zones')->nullable();
            $table->boolean('has_completed_setup')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('runner_profiles');
    }
};
