<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Welche Variante aus dem Bausteinkatalog (App\Services\Run\RunWorkouts)
 * die Einheit trägt. Damit lässt sich ihr Ablauf neu bauen, ohne zu raten — nach
 * einer Änderung der Dauer, beim Coach oder per `sessions:rebuild-steps`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->string('workout_variant', 40)->nullable()->after('steps');
        });
    }

    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropColumn('workout_variant');
        });
    }
};
