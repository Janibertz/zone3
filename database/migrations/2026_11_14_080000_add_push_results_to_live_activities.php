<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Was Apple auf den letzten Push geantwortet hat. Ohne das war nicht zu
 * sehen, ob eine hängende Live Activity nie ein Ende bekam oder Apple es
 * abgelehnt hat (14.11.2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_activities', function (Blueprint $table) {
            $table->string('last_event', 10)->nullable()->after('state_hash');
            $table->string('last_result', 80)->nullable()->after('last_event');
            $table->timestamp('last_push_at')->nullable()->after('last_result');
        });
        Schema::table('live_activity_devices', function (Blueprint $table) {
            $table->string('last_result', 80)->nullable()->after('started_on');
            $table->timestamp('last_push_at')->nullable()->after('last_result');
        });
    }

    public function down(): void
    {
        Schema::table('live_activities', fn (Blueprint $t) => $t->dropColumn(['last_event', 'last_result', 'last_push_at']));
        Schema::table('live_activity_devices', fn (Blueprint $t) => $t->dropColumn(['last_result', 'last_push_at']));
    }
};
