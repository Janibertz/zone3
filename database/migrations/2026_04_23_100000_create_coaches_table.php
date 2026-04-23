<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coaches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('specialty'); // motivator | strategist | companion
            $table->string('tagline');
            $table->text('description');
            $table->string('avatar_color'); // tailwind color for avatar bg
            $table->string('avatar_initials', 3);
            $table->text('personality_prompt');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coaches');
    }
};
