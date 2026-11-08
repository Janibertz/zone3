<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WorkoutKit (08.11.2026): ob die iOS-App die nächsten Plantage selbst auf
 * die Apple Watch legt. Standard an, wie bei Garmin; wirken kann es erst,
 * wenn der Athlet es in iOS erlaubt hat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('apple_watch_sync')->default(true)->after('garmin_workout_sync');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('apple_watch_sync');
        });
    }
};
