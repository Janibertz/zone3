<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Push aus der iOS-App läuft über APNs, nicht über Web-Push. Ein
 * Gerätetoken steht in `endpoint`, Schlüssel gibt es dort keine.
 * Dieselbe Tabelle, damit jede Stelle, die nach `pushSubscriptions`
 * fragt (Erinnerungen, Checkliste, Admin), die App-Geräte mitzählt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->string('kind', 10)->default('web')->after('user_id');
            $table->string('public_key')->nullable()->change();
            $table->string('auth_token')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('push_subscriptions')->where('kind', 'apns')->delete();

        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropColumn('kind');
            $table->string('public_key')->nullable(false)->change();
            $table->string('auth_token')->nullable(false)->change();
        });
    }
};
