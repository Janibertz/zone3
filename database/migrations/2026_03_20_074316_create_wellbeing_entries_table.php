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
        Schema::create('wellbeing_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date')->index();
            $table->integer('energy_level'); // 1-10
            $table->integer('mood'); // 1-10
            $table->integer('sleep_quality'); // 1-10
            $table->integer('muscle_soreness'); // 1-10 DOMS
            $table->integer('stress_level'); // 1-10
            $table->text('notes')->nullable();
            $table->boolean('is_sick')->default(false);
            $table->boolean('is_injured')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wellbeing_entries');
    }
};
