<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Woher ein neues Konto kam (10.11.2026, Jan: „messen, was Anmeldungen
 * bringt“): der `?ref=`- bzw. `utm_source`-Wert des ersten Besuchs,
 * „einladung“ für einen Einladungslink, sonst leer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('signup_source', 40)->nullable()->after('waitlisted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('signup_source');
        });
    }
};
