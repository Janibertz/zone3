<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live Activity „Heute steht an“ (Jan, 12.11.2026). Zwei Sorten Tokens:
 * je Gerät das Start-Token (der Server startet die Activity per Push) und
 * je laufender Activity das Update-Token (Erledigt, Ende).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_activity_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('start_token', 255)->unique();
            // An welchem Tag der Start schon rausging — einmal am Tag je Gerät.
            $table->date('started_on')->nullable();
            $table->timestamps();
        });

        Schema::create('live_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('activity_id', 100)->unique();
            $table->date('day');
            $table->string('push_token', 255);
            // Was zuletzt geschickt wurde — ohne Änderung kein Push.
            $table->string('state_hash', 64)->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['day', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_activities');
        Schema::dropIfExists('live_activity_devices');
    }
};
