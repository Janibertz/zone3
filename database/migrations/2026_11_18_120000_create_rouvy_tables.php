<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ROUVY Public API (18.11.2026).
 *
 * - `rouvy_connections`: der Zugang. Access-Token 1 h, Refresh-Token rotiert
 *   (ROUVY: „always persist the refresh_token from the latest response“).
 * - `rouvy_scheduled_workouts`: welche Radeinheit als welches Workout in der
 *   ROUVY-Bibliothek steht. ROUVY kennt kein Ändern und keine eigene
 *   Kennung — nur die `workoutId` aus der Antwort, mit der gelöscht wird.
 * - `activities.rouvy_start`: Kennung einer Fahrt = Startzeit wie beim
 *   FIT-Upload; ROUVYs ID ist ein String und steht daneben.
 * - `rouvy_webhook_events`: jeder Aufruf, vor jeder Filterung. `webhook_id`
 *   bleibt bei Wiederholungen gleich und dient zum Entdoppeln.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rouvy_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('rouvy_user_id', 64)->unique();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('scopes')->nullable();
            $table->timestamp('ai_consent_at')->nullable();
            $table->timestamp('workouts_synced_at')->nullable();
            $table->timestamp('activities_synced_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
        });

        Schema::create('rouvy_scheduled_workouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('training_session_id')->nullable()->index();
            $table->string('workout_id', 32);
            $table->date('planned_date');
            $table->string('payload_hash', 64);
            $table->timestamps();
            $table->unique(['user_id', 'training_session_id']);
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->unsignedBigInteger('rouvy_start')->nullable()->after('intervals_activity_id');
            $table->string('rouvy_activity_id', 64)->nullable()->after('rouvy_start');
            $table->unique(['user_id', 'rouvy_start']);
            $table->index('rouvy_activity_id');
        });

        Schema::create('rouvy_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('webhook_id', 64)->nullable()->unique();
            $table->string('type', 40);
            $table->string('owner_id', 64)->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->string('outcome', 40);
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rouvy_webhook_events');

        Schema::table('activities', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'rouvy_start']);
            $table->dropIndex(['rouvy_activity_id']);
            $table->dropColumn(['rouvy_start', 'rouvy_activity_id']);
        });

        Schema::dropIfExists('rouvy_scheduled_workouts');
        Schema::dropIfExists('rouvy_connections');
    }
};
