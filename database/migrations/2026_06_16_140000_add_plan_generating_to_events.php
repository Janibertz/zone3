<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Async plan generation state — plan creation runs in a queued job so the
            // single-threaded web process is never blocked by a 100s+ OpenAI call.
            $table->boolean('plan_generating')->default(false)->after('target_yards');
            $table->text('plan_error')->nullable()->after('plan_generating');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['plan_generating', 'plan_error']);
        });
    }
};
