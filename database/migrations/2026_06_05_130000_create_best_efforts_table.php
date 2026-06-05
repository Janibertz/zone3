<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-activity "best effort" splits imported from Strava
     * (fastest 1k/5k/10k/half/marathon section within a run).
     * Personal records = the fastest rows per distance across all activities.
     */
    public function up(): void
    {
        Schema::create('best_efforts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('distance_key');                 // 1k | 5k | 10k | half | marathon
            $table->unsignedInteger('distance_m');          // canonical distance in meters
            $table->unsignedInteger('elapsed_time');        // seconds for this effort
            $table->dateTime('achieved_at');                // = activity.start_date
            $table->timestamps();

            // One effort per (activity, distance) — idempotent upserts on re-sync
            $table->unique(['activity_id', 'distance_key'], 'be_activity_distance_unique');
            // Top-N leaderboard per distance
            $table->index(['user_id', 'distance_key', 'elapsed_time'], 'be_user_distance_time_idx');
            // History chart per distance
            $table->index(['user_id', 'distance_key', 'achieved_at'], 'be_user_distance_date_idx');
        });

        Schema::table('activities', function (Blueprint $table) {
            // Marker so the backfill never re-fetches an already-processed run,
            // even when it legitimately has no best efforts (too short / no GPS).
            $table->dateTime('best_efforts_synced_at')->nullable()->after('polyline');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('best_efforts_synced_at');
        });

        Schema::dropIfExists('best_efforts');
    }
};
