<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cached AI race-day strategy and post-race analysis on the training plan.
     */
    public function up(): void
    {
        Schema::table('training_plans', function (Blueprint $table) {
            $table->text('race_strategy_text')->nullable()->after('prediction_updated_at');
            $table->text('race_analysis_text')->nullable()->after('race_strategy_text');
            $table->foreignId('race_analysis_activity_id')->nullable()->after('race_analysis_text')
                ->constrained('activities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('training_plans', function (Blueprint $table) {
            $table->dropForeign(['race_analysis_activity_id']);
            $table->dropColumn(['race_strategy_text', 'race_analysis_text', 'race_analysis_activity_id']);
        });
    }
};
