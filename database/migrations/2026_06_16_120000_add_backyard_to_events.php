<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Backyard Ultra goal: number of yards/hours (1 yard = 1 hour = one 6.706 km loop).
            // Used instead of target_time_* when race_distance = 'backyard_ultra'.
            $table->unsignedSmallInteger('target_yards')->nullable()->after('target_time_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('target_yards');
        });
    }
};
