<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('push_notifications_enabled')->default(false)->after('remember_token');
            $table->time('wellbeing_reminder_time')->nullable()->after('push_notifications_enabled'); // e.g. "08:00"
            $table->boolean('notify_threshold_pace')->default(true)->after('wellbeing_reminder_time');
            $table->boolean('notify_plan_updated')->default(true)->after('notify_threshold_pace');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'push_notifications_enabled',
                'wellbeing_reminder_time',
                'notify_threshold_pace',
                'notify_plan_updated',
            ]);
        });
    }
};
