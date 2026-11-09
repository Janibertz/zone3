<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Worin Zone3 benutzt wird (09.11.2026, Jan: „wer die App oder die normale
 * Seite nutzt“): je Weg nur der letzte Tag, kein Gerät, keine Adresse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('seen_app_on')->nullable();
            $table->date('seen_homescreen_on')->nullable();
            $table->date('seen_browser_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['seen_app_on', 'seen_homescreen_on', 'seen_browser_on']);
        });
    }
};
