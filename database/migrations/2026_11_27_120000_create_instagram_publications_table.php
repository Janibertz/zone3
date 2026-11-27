<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Was instagram:publish mit einem Beitrag aus resources/content/instagram
 * gemacht hat (seit 27.11.2026). Der Beitrag selbst steht im Repo, hier nur
 * sein Zustand: waiting (Container angelegt, Instagram verarbeitet noch),
 * published, failed, missed (zu spät entdeckt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_publications', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('type', 20);
            $table->timestamp('scheduled_at');
            $table->string('status', 20);
            $table->string('container_id')->nullable();
            $table->string('media_id')->nullable();
            $table->string('permalink')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_publications');
    }
};
