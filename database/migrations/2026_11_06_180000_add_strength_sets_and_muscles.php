<?php

use App\Services\Strength\Muscles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kraft aus der Uhr-Datei (06.11.2026).
 *
 * - `strength_exercise_aliases`: wie Garmin, COROS und spätere Anbieter eine
 *   Katalog-Übung nennen. Die Garmin-Spalten von PR #66 ziehen hier ein und
 *   fallen weg — eine Zuordnung für alle Uhren (Jans Entscheidung).
 * - `activities.strength_sets`: die Sätze eines Krafttrainings, wie die Uhr
 *   sie aufgezeichnet hat (siehe `StrengthSets`).
 * - `strength_exercises.muscles`: Haupt- und Nebenmuskeln je Übung für die
 *   Körpergrafik. Startwerte aus `Muscles::CATALOG`, danach pflegt sie der
 *   Admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strength_exercise_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strength_exercise_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('external_key', 160);
            $table->timestamps();

            $table->unique(['provider', 'external_key']);
            $table->index(['strength_exercise_id', 'provider']);
        });

        $now = now();
        foreach (DB::table('strength_exercises')->whereNotNull('garmin_category')->whereNotNull('garmin_name')->get() as $e) {
            DB::table('strength_exercise_aliases')->insertOrIgnore([
                'strength_exercise_id' => $e->id,
                'provider'             => 'garmin',
                'external_key'         => strtoupper($e->garmin_category) . '/' . strtoupper($e->garmin_name),
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
        }

        Schema::table('strength_exercises', function (Blueprint $table) {
            $table->dropColumn(['garmin_category', 'garmin_name']);
        });

        Schema::table('strength_exercises', function (Blueprint $table) {
            $table->json('muscles')->nullable();
        });

        foreach (Muscles::CATALOG as $key => [$primary, $secondary]) {
            DB::table('strength_exercises')->where('key', $key)
                ->update(['muscles' => json_encode(Muscles::clean($primary, $secondary))]);
        }

        Schema::table('activities', function (Blueprint $table) {
            $table->json('strength_sets')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('strength_sets');
        });

        Schema::table('strength_exercises', function (Blueprint $table) {
            $table->dropColumn('muscles');
            $table->string('garmin_category', 40)->nullable();
            $table->string('garmin_name', 80)->nullable();
        });

        foreach (DB::table('strength_exercise_aliases')->where('provider', 'garmin')->get() as $a) {
            [$category, $name] = explode('/', $a->external_key, 2) + [null, null];
            DB::table('strength_exercises')->where('id', $a->strength_exercise_id)
                ->update(['garmin_category' => $category, 'garmin_name' => $name]);
        }

        Schema::dropIfExists('strength_exercise_aliases');
    }
};
