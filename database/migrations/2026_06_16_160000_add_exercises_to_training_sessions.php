<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            // Structured exercise list for strength/core sessions:
            // [{ "name", "sets", "reps", "load", "note" }]
            $table->json('exercises')->nullable()->after('steps');
        });
    }

    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropColumn('exercises');
        });
    }
};
