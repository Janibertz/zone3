<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apples Bewertungsdialog kommt einmal je Konto, nach der ersten geschafften
 * Planwoche in der iOS-App (seit 25.11.2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('review_prompted_at')->nullable()->after('moment_race_seen');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('review_prompted_at');
        });
    }
};
