<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COROS ueber MCP (OAuth2 mit PKCE, dynamisch registrierter Client).
 *
 * `client_id` steht an der Verbindung: ein Refresh-Token gilt nur fuer den
 * Client, der ihn bekommen hat. Geht die zwischengespeicherte Registrierung
 * verloren, registriert Zone3 neu — alte Verbindungen erneuern weiter mit
 * ihrem eigenen Client.
 *
 * `coros_scheduled_workouts`: was Zone3 in den COROS-Kalender gelegt hat.
 * MCP kann dort nichts loeschen oder verschieben, nur ersetzen. Eine Zeile
 * ist deshalb ein Platz an einem Tag (`id_in_plan`), der bleibt: faellt die
 * Einheit weg, wird er zu „Entfaellt“ (`cancelled`) und spaeter fuer eine
 * neue Einheit an demselben Tag wieder benutzt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coros_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // `sub` aus dem id_token — ein COROS-Konto gehoert einem Athleten.
            $table->string('coros_user_id')->nullable()->unique();
            $table->string('client_id');

            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('access_token_expires_at')->nullable();

            $table->boolean('workout_sync')->default(true);

            $table->timestamp('last_refreshed_at')->nullable();
            $table->string('refresh_error')->nullable();
            $table->timestamp('refresh_error_at')->nullable();

            $table->timestamps();
        });

        Schema::create('coros_scheduled_workouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_session_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->string('id_in_plan');
            $table->string('payload_hash', 40)->nullable();
            $table->boolean('cancelled')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coros_scheduled_workouts');
        Schema::dropIfExists('coros_connections');
    }
};
