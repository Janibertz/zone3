<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->text('coach_review')->nullable()->after('feeling_notes');       // AI-generated post-session review
            $table->string('review_question', 300)->nullable()->after('coach_review'); // follow-up question to the athlete
            $table->json('review_options')->nullable()->after('review_question');    // tap-answer chips for the question
            $table->string('review_feedback', 300)->nullable()->after('review_options'); // athlete's answer
            $table->timestamp('reviewed_at')->nullable()->after('review_feedback');  // when the review was generated
        });
    }

    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropColumn(['coach_review', 'review_question', 'review_options', 'review_feedback', 'reviewed_at']);
        });
    }
};
