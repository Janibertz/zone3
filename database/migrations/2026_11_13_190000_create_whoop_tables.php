<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHOOP als Quelle (13.11.2026).
 *
 * - `whoop_connections`: der Zugang (OAuth2 mit Refresh-Token, der bei jeder
 *   Erneuerung wechselt).
 * - `activities.whoop_start`: Kennung eines WHOOP-Trainings = Startzeit (UTC,
 *   Unix-Sekunden) wie bei Polar und Apple — WHOOPs ID ist eine UUID, die
 *   Grabsteine und `ActivityImportService::store()` rechnen mit Zahlen.
 *   `whoop_workout_id` hält die UUID daneben, weil `workout.deleted` nur sie nennt.
 * - `whoop_webhook_events`: jeder Aufruf von WHOOP, vor jeder Filterung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whoop_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('whoop_user_id')->unique();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('scopes')->nullable();
            $table->timestamp('ai_consent_at')->nullable();
            $table->timestamp('activities_synced_at')->nullable();
            $table->timestamp('health_synced_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->unsignedBigInteger('whoop_start')->nullable()->after('polar_start');
            $table->uuid('whoop_workout_id')->nullable()->after('whoop_start');
            $table->unique(['user_id', 'whoop_start']);
            $table->index('whoop_workout_id');
        });

        Schema::create('whoop_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->unsignedBigInteger('whoop_user_id')->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->string('outcome', 40);
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whoop_webhook_events');

        Schema::table('activities', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'whoop_start']);
            $table->dropIndex(['whoop_workout_id']);
            $table->dropColumn(['whoop_start', 'whoop_workout_id']);
        });

        Schema::dropIfExists('whoop_connections');
    }
};
