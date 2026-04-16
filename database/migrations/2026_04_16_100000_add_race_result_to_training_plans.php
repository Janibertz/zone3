<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_plans', function (Blueprint $table) {
            $table->unsignedTinyInteger('actual_time_hours')->nullable()->after('context');
            $table->unsignedTinyInteger('actual_time_minutes')->nullable()->after('actual_time_hours');
            $table->unsignedTinyInteger('overall_rating')->nullable()->after('actual_time_minutes'); // 1–5 stars
            $table->text('result_notes')->nullable()->after('overall_rating');
        });
    }

    public function down(): void
    {
        Schema::table('training_plans', function (Blueprint $table) {
            $table->dropColumn(['actual_time_hours', 'actual_time_minutes', 'overall_rating', 'result_notes']);
        });
    }
};
