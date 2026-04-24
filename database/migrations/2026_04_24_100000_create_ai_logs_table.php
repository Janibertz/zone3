<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('call_type'); // recommendation, plan, weekly_review, pace_zones, nutrition, adjust_session, goal_analysis, threshold_pace, adjust_recommendation
            $table->string('model')->default('gpt-4o');
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('cost_eur', 10, 6)->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('prompt_preview')->nullable();      // erste 500 Zeichen
            $table->text('response_preview')->nullable();    // erste 500 Zeichen
            $table->longText('full_prompt')->nullable();
            $table->longText('full_response')->nullable();
            $table->string('status')->default('success');   // success | error
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['call_type', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_logs');
    }
};
