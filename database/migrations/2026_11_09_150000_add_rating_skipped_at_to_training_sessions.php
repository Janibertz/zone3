<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * „Nicht bewerten“ (09.11.2026, Jan: „ich muss eine Bewertung hinterlassen,
 * obwohl ich das gar nicht will“). Eine Einheit ohne Bewertung verschwindet
 * damit aus „Wie lief's?“, ohne dass eine erfundene Bewertung im Prompt
 * landet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->timestamp('rating_skipped_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropColumn('rating_skipped_at');
        });
    }
};
