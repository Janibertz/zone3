<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_tracks', function (Blueprint $table) {
            // Rennstand von Hand: solange null, rechnet die Uhr weiter.
            // Sobald gesetzt, friert die Seite bei dieser Rundenzahl ein.
            $table->unsignedSmallInteger('stopped_at_yard')->nullable()->after('target_yards');
            $table->string('outcome', 20)->nullable()->after('stopped_at_yard'); // finished | dnf

            // Garmins Karte einbetten. Die Adresse enthaelt den Token —
            // deshalb eine bewusste Entscheidung, kein Standard.
            $table->boolean('embed_map')->default(false)->after('outcome');
        });
    }

    public function down(): void
    {
        Schema::table('live_tracks', function (Blueprint $table) {
            $table->dropColumn(['stopped_at_yard', 'outcome', 'embed_map']);
        });
    }
};
