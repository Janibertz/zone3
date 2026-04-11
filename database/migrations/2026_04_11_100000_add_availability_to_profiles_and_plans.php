<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Weekly default availability on runner profile
        Schema::table('runner_profiles', function (Blueprint $table) {
            $table->json('weekly_availability')->nullable()->after('pace_zones');
        });

        // Per-date overrides on training plans
        Schema::table('training_plans', function (Blueprint $table) {
            $table->json('availability_overrides')->nullable()->after('needs_plan_update');
        });
    }

    public function down(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table) {
            $table->dropColumn('weekly_availability');
        });
        Schema::table('training_plans', function (Blueprint $table) {
            $table->dropColumn('availability_overrides');
        });
    }
};
