<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * App Store 5.1.1(v): Konto löschen muss in der App gehen. Wer mit Apple
 * oder per Link angemeldet ist (seit 09.11.2026), hat nur ein zufälliges
 * Passwort und kam an der Passwortabfrage nie vorbei. Ohne eigenes Passwort
 * bestätigt man stattdessen mit einem Wort.
 *
 * Bestand: Konten seit dem 09.11. ohne Einladungslink sind über Access
 * entstanden. Ein Irrtum hier kostet nur die Passwortabfrage, nie das Löschen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('has_password')->default(true)->after('password');
        });

        DB::table('users')
            ->where('created_at', '>=', '2026-11-09 00:00:00')
            ->whereNull('invitation_id')
            ->update(['has_password' => false]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('has_password');
        });
    }
};
