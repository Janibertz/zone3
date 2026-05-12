<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table) {
            $table->text('daily_message')->nullable()->after('today_recommendation');
            $table->date('daily_message_date')->nullable()->after('daily_message');
        });
    }

    public function down(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table) {
            $table->dropColumn(['daily_message', 'daily_message_date']);
        });
    }
};
