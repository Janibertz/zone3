<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table) {
            $table->text('today_recommendation')->nullable()->after('pace_zones');
            $table->date('recommendation_date')->nullable()->after('today_recommendation');
            $table->unsignedBigInteger('recommendation_wellbeing_id')->nullable()->after('recommendation_date');

            $table->foreign('recommendation_wellbeing_id')->references('id')->on('wellbeing_entries')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table) {
            $table->dropForeign(['recommendation_wellbeing_id']);
            $table->dropColumn(['today_recommendation', 'recommendation_date', 'recommendation_wellbeing_id']);
        });
    }
};
