<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->json('sessions');           // array of 10 daily training sessions
            $table->json('context')->nullable(); // snapshot of data used for generation
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_plans');
    }
};
