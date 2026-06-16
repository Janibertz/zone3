<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table) {
            // Strength & core training preferences — feed the AI plan generation.
            $table->boolean('strength_enabled')->default(false)->after('weekly_availability');
            $table->unsignedTinyInteger('strength_days_per_week')->default(2)->after('strength_enabled');
            $table->json('strength_equipment')->nullable()->after('strength_days_per_week'); // ['kettlebell','dumbbells','gym','bodyweight','band']
            $table->string('strength_experience')->nullable()->after('strength_equipment');   // beginner|intermediate|advanced
        });
    }

    public function down(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table) {
            $table->dropColumn(['strength_enabled', 'strength_days_per_week', 'strength_equipment', 'strength_experience']);
        });
    }
};
