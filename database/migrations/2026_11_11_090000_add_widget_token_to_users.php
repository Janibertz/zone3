<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * iOS-Widget (11.11.2026): das Widget holt seine Daten selbst, auch wenn die
 * App zu ist. Es hat keine Session, darum ein eigener Token wie beim
 * Kalender-Abo — die App legt ihn im geteilten Schlüsselbund ab.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('widget_token', 64)->nullable()->unique()->after('calendar_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['widget_token']);
            $table->dropColumn('widget_token');
        });
    }
};
