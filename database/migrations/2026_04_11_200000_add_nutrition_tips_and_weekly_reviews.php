<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cache nutrition tips on session so we don't re-call OpenAI every open
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->json('nutrition_tips')->nullable()->after('feeling_notes');
        });

        // Weekly AI review, generated every Monday, stored for the week
        Schema::create('weekly_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');   // Monday of that week
            $table->text('content');      // AI-generated Markdown text
            $table->timestamps();

            $table->unique(['user_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropColumn('nutrition_tips');
        });
        Schema::dropIfExists('weekly_reviews');
    }
};
