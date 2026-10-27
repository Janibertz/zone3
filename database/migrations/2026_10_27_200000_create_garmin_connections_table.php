<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die offizielle Garmin-Verbindung (OAuth2 mit PKCE).
 *
 * Eine eigene Tabelle statt Spalten auf `users`, damit die Tokens an keiner
 * Stelle mit dem Nutzer ins Frontend wandern koennen. Die Tokens sind
 * verschluesselt (Cast `encrypted`) und darum `text`: der Chiffretext ist
 * deutlich laenger als der Token.
 *
 * `garmin_webhook_events` ist das Gegenstueck zu `strava_webhook_events`:
 * jeder Anruf von Garmin, vor jeder Filterung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('garmin_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('garmin_user_id')->unique();

            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('access_token_expires_at')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();

            // Was der Athlet im Garmin-Dialog freigegeben hat, z. B.
            // ACTIVITY_EXPORT, HEALTH_EXPORT, WORKOUT_IMPORT.
            $table->json('permissions')->nullable();
            $table->timestamp('permissions_checked_at')->nullable();

            // Einwilligung in die KI-Auswertung der Garmin-Daten (Vorgabe von
            // Garmin, /privacy Abschnitt 7). Ohne sie gibt es keine Verbindung.
            $table->timestamp('ai_consent_at');

            $table->timestamp('last_refreshed_at')->nullable();
            $table->string('refresh_error')->nullable();
            $table->timestamp('refresh_error_at')->nullable();

            $table->timestamps();
        });

        Schema::create('garmin_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('garmin_user_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garmin_webhook_events');
        Schema::dropIfExists('garmin_connections');
    }
};
