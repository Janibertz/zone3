<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Start coordinates from Strava (start_latlng) — used to resolve the
     * user's training location for weather lookups.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->decimal('start_lat', 10, 7)->nullable()->after('best_efforts_synced_at');
            $table->decimal('start_lng', 10, 7)->nullable()->after('start_lat');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['start_lat', 'start_lng']);
        });
    }
};
