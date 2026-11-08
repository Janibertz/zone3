<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIT-Upload als vierte Quelle (08.11.2026). Eine Datei hat keine ID wie
 * Strava oder Garmin; ihre Kennung ist die Startzeit aus der Datei (Unix-
 * Sekunden). Eindeutig je Athlet — zwei Athleten können zur selben Sekunde
 * losfahren.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->unsignedBigInteger('file_start')->nullable()->after('coros_label_id');
            $table->unique(['user_id', 'file_start']);
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'file_start']);
            $table->dropColumn('file_start');
        });
    }
};
