<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * intervals.icu als Brücke zu Zwift (15.11.2026).
 *
 * - `intervals_connections`: der Zugang. intervals.icu kennt keinen
 *   Refresh-Token; der Access-Token gilt, bis der Athlet ihn widerruft.
 * - `intervals_scheduled_workouts`: welche Radeinheit als welches Event im
 *   intervals-Kalender steht. Eigene Tabelle wie bei Garmin: eine
 *   Neuberechnung löscht Einheiten, und ohne diese Zeilen blieben die Events
 *   für immer im Kalender (und damit in Zwift).
 * - `activities.intervals_start`: Kennung einer Fahrt = Startzeit wie beim
 *   FIT-Upload; intervals' ID ist ein String („i55751783“) und steht daneben,
 *   weil `ACTIVITY_DELETED` nur sie nennt.
 * - `intervals_webhook_events`: jeder Aufruf, vor jeder Filterung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intervals_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('athlete_id', 32)->unique();
            $table->text('access_token');
            $table->string('scopes')->nullable();
            $table->timestamp('ai_consent_at')->nullable();
            $table->timestamp('workouts_synced_at')->nullable();
            $table->timestamp('activities_synced_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
        });

        Schema::create('intervals_scheduled_workouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('training_session_id')->nullable()->index();
            $table->string('external_id', 64);
            $table->string('event_id', 32)->nullable();
            $table->date('planned_date');
            $table->string('payload_hash', 64);
            $table->timestamps();
            $table->unique(['user_id', 'external_id']);
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->unsignedBigInteger('intervals_start')->nullable()->after('whoop_workout_id');
            $table->string('intervals_activity_id', 32)->nullable()->after('intervals_start');
            $table->unique(['user_id', 'intervals_start']);
            $table->index('intervals_activity_id');
        });

        Schema::create('intervals_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('athlete_id', 32)->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->string('outcome', 40);
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intervals_webhook_events');

        Schema::table('activities', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'intervals_start']);
            $table->dropIndex(['intervals_activity_id']);
            $table->dropColumn(['intervals_start', 'intervals_activity_id']);
        });

        Schema::dropIfExists('intervals_scheduled_workouts');
        Schema::dropIfExists('intervals_connections');
    }
};
