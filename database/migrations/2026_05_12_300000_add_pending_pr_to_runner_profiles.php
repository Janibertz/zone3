<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table) {
            $table->unsignedBigInteger('pending_pr_activity_id')->nullable()->after('daily_message_date');
            $table->text('pending_pr_message')->nullable()->after('pending_pr_activity_id');
        });
    }

    public function down(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table) {
            $table->dropColumn(['pending_pr_activity_id', 'pending_pr_message']);
        });
    }
};
