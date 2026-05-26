<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('easy_run'); // easy_run|tempo_run|interval|long_run
            $table->text('description')->nullable();
            $table->json('blocks');          // array of block objects (see WorkoutController)
            $table->json('tags')->nullable(); // ['#intervall', '#tempo']
            $table->decimal('estimated_distance_km', 6, 2)->nullable();
            $table->unsignedSmallInteger('estimated_duration_min')->nullable();
            $table->unsignedSmallInteger('times_used')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workouts');
    }
};
