<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add composite indexes for the most frequent query shapes.
     *
     * activities are almost always filtered by user_id together with a
     * start_date range/sort (dashboard, training load, statistics), and
     * often additionally by type = 'Run'. training_sessions are looked up
     * by plan + date and by user + status. (activity_id already has a
     * single-column index from its foreign-key constraint.)
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->index(['user_id', 'start_date'], 'activities_user_start_idx');
            $table->index(['user_id', 'type', 'start_date'], 'activities_user_type_start_idx');
        });

        Schema::table('training_sessions', function (Blueprint $table) {
            $table->index(['training_plan_id', 'planned_date'], 'ts_plan_date_idx');
            $table->index(['user_id', 'status'], 'ts_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex('activities_user_start_idx');
            $table->dropIndex('activities_user_type_start_idx');
        });

        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropIndex('ts_plan_date_idx');
            $table->dropIndex('ts_user_status_idx');
        });
    }
};
