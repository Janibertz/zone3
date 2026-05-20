<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_plans', function (Blueprint $table) {
            $table->string('predicted_finish_time')->nullable()->after('result_notes');
            $table->string('predicted_pace')->nullable()->after('predicted_finish_time');
            $table->string('prediction_trend')->nullable()->after('predicted_pace'); // improving|stable|declining
            $table->integer('prediction_target_delta_sec')->nullable()->after('prediction_trend'); // positive = ahead of goal
            $table->unsignedSmallInteger('prediction_run_count')->nullable()->after('prediction_target_delta_sec');
            $table->text('prediction_text')->nullable()->after('prediction_run_count');
            $table->timestamp('prediction_updated_at')->nullable()->after('prediction_text');
        });
    }

    public function down(): void
    {
        Schema::table('training_plans', function (Blueprint $table) {
            $table->dropColumn([
                'predicted_finish_time',
                'predicted_pace',
                'prediction_trend',
                'prediction_target_delta_sec',
                'prediction_run_count',
                'prediction_text',
                'prediction_updated_at',
            ]);
        });
    }
};
