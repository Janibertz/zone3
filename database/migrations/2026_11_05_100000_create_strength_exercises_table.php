<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kraft-Katalog (05.11.2026).
 *
 * Bisher schrieb das Modell die Übungen einer Krafteinheit frei — Namen,
 * die es so nirgends gibt, ohne Bild und ohne Ausführung. Jetzt gibt es einen
 * festen Katalog, den der Admin pflegt; das Modell wählt nur Schlüssel daraus
 * und legt Sätze und Wiederholungen fest.
 *
 * Bilder liegen in einer eigenen Tabelle in der Datenbank, nicht auf der
 * Platte: `storage/` ist im Container flüchtig, und jede Liste der Übungen
 * soll ohne die Bilddaten auskommen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strength_exercises', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('name', 120);
            $table->text('instructions')->nullable();
            $table->string('group', 30);
            $table->json('equipment');
            $table->string('level', 20)->default('beginner');
            $table->unsignedTinyInteger('default_sets')->default(3);
            $table->string('default_reps', 30)->default('10');
            $table->string('default_load', 60)->nullable();
            $table->unsignedInteger('wger_id')->nullable();
            $table->string('license', 40)->nullable();
            $table->string('license_author', 120)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('strength_exercise_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strength_exercise_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('mime', 40)->default('image/jpeg');
            $table->longText('data'); // base64
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strength_exercise_images');
        Schema::dropIfExists('strength_exercises');
    }
};
