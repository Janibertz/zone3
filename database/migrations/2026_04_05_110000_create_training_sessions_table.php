<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->date('planned_date');
            $table->string('type');           // rest, easy_run, tempo_run, interval, long_run, race_prep
            $table->string('title');
            $table->text('description');
            $table->float('distance_km')->nullable();
            $table->unsignedSmallInteger('duration_min')->nullable();
            $table->string('pace_target')->nullable();
            $table->unsignedTinyInteger('zone')->nullable();
            $table->string('intensity');      // rest, low, medium, high
            $table->string('status')->default('planned'); // planned, completed, skipped
            $table->string('skip_reason')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_sessions');
    }
};
