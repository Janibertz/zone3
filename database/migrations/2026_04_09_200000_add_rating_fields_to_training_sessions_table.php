<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->tinyInteger('rating')->unsigned()->nullable()->after('skip_reason');          // 1–5 Sterne
            $table->tinyInteger('effort_perceived')->unsigned()->nullable()->after('rating');     // RPE 1–10
            $table->string('feeling_notes', 300)->nullable()->after('effort_perceived');         // Freitext
        });
    }

    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropColumn(['rating', 'effort_perceived', 'feeling_notes']);
        });
    }
};
