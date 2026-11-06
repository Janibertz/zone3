<?php

use App\Services\Strength\Muscles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kraft aus der FIT-Datei (06.11.2026).
 *
 * `activities.strength_sets`: die Sätze eines Krafttrainings, wie die Uhr
 * sie aufgezeichnet hat (siehe `StrengthSets`). `strength_exercises.muscles`:
 * Haupt- und Nebenmuskeln je Katalog-Übung für die Körpergrafik — Startwerte
 * aus `Muscles::CATALOG`, danach pflegt sie der Admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->json('strength_sets')->nullable();
        });

        Schema::table('strength_exercises', function (Blueprint $table) {
            $table->json('muscles')->nullable()->after('garmin_name');
        });

        foreach (Muscles::CATALOG as $key => [$primary, $secondary]) {
            DB::table('strength_exercises')->where('key', $key)
                ->update(['muscles' => json_encode(Muscles::clean($primary, $secondary))]);
        }
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('strength_sets');
        });

        Schema::table('strength_exercises', function (Blueprint $table) {
            $table->dropColumn('muscles');
        });
    }
};
