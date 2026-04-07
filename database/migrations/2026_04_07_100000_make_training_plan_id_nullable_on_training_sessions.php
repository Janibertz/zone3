<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropForeign(['training_plan_id']);
            $table->unsignedBigInteger('training_plan_id')->nullable()->change();
            $table->foreign('training_plan_id')
                ->references('id')->on('training_plans')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropForeign(['training_plan_id']);
            $table->unsignedBigInteger('training_plan_id')->nullable(false)->change();
            $table->foreign('training_plan_id')
                ->references('id')->on('training_plans')
                ->cascadeOnDelete();
        });
    }
};
