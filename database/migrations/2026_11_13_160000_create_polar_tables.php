<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Polar AccessLink als Aktivitätsquelle (13.11.2026).
 *
 * - `polar_connections`: der Zugang. Polar kennt keinen Refresh-Token, ein
 *   Access-Token gilt, bis er widerrufen wird.
 * - `activities.polar_start`: Kennung eines Polar-Trainings = Startzeit
 *   (UTC, Unix-Sekunden), wie bei FIT-Datei und Apple Health. Polars eigene
 *   ID ist ein Hash-String, die Grabsteine (`ignored_activities`) und
 *   `ActivityImportService::store()` rechnen mit Zahlen.
 * - `polar_webhook_events`: jeder Aufruf von Polar, vor jeder Filterung —
 *   die Produktion schreibt ihr Log in den Container-Strom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polar_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('polar_user_id')->unique();
            $table->text('access_token');
            $table->timestamp('ai_consent_at')->nullable();
            $table->string('device_name')->nullable();
            $table->timestamp('activities_synced_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->unsignedBigInteger('polar_start')->nullable()->after('apple_start');
            $table->unique(['user_id', 'polar_start']);
        });

        Schema::create('polar_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event', 40);
            $table->unsignedBigInteger('polar_user_id')->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->string('outcome', 40);
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polar_webhook_events');

        Schema::table('activities', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'polar_start']);
            $table->dropColumn('polar_start');
        });

        Schema::dropIfExists('polar_connections');
    }
};
