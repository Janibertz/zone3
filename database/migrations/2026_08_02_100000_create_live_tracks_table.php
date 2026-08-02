<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Unratebarer Zugangsschluessel fuer die oeffentliche Seite.
            $table->string('slug', 32)->unique();

            $table->string('title')->default('Backyard Ultra');
            $table->timestamp('starts_at');

            // Backyard: Rundenlaenge und Zielrunden. Die Yard-Uhr rechnet
            // allein aus starts_at, damit sie auch ohne Garmin weiterlaeuft.
            $table->decimal('yard_km', 6, 3)->default(6.706);
            $table->unsignedSmallInteger('target_yards')->nullable();

            // LiveTrack-Sitzung. Token verschluesselt, nie im Frontend.
            $table->string('garmin_session_id')->nullable();
            $table->text('garmin_token')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_polled_at')->nullable();
            $table->text('last_error')->nullable();

            // Normalisierter Stand + auf eine Minute verduennte Messreihe.
            $table->json('state')->nullable();
            $table->json('series')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_tracks');
    }
};
