<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COROS als dritte Aktivitaetsquelle (06.11.2026). Die `labelId` von COROS
 * ist eine 18-stellige Zahl und passt in ein BIGINT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->unsignedBigInteger('coros_label_id')->nullable()->unique()->after('garmin_activity_id');
        });

        Schema::table('coros_connections', function (Blueprint $table) {
            $table->timestamp('activities_synced_at')->nullable()->after('health_synced_at');
            $table->string('device_name')->nullable()->after('activities_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('coros_connections', fn (Blueprint $table) => $table->dropColumn(['activities_synced_at', 'device_name']));

        Schema::table('activities', function (Blueprint $table) {
            $table->dropUnique(['coros_label_id']);
            $table->dropColumn('coros_label_id');
        });
    }
};
