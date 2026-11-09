<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anmelden mit Apple und Warteliste (09.11.2026).
 *
 * `waitlisted_at` ist gesetzt, solange ein Konto auf die Freischaltung
 * wartet — null heißt freigeschaltet. So bleiben alle bestehenden Konten
 * ohne Datenmigration drin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('apple_sub')->nullable()->unique()->after('email');
            $table->timestamp('waitlisted_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['apple_sub']);
            $table->dropColumn(['apple_sub', 'waitlisted_at']);
        });
    }
};
