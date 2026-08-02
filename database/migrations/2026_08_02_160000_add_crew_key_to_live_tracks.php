<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_tracks', function (Blueprint $table) {
            // Zweiter, eigener Schluessel fuer die Crew. Bewusst getrennt vom
            // oeffentlichen Slug: wer nur zuschaut, soll nichts steuern koennen.
            $table->string('crew_key', 32)->nullable()->after('slug');

            // Kurze Lagemeldung der Crew, erscheint auf der oeffentlichen Seite.
            $table->string('status_note', 140)->nullable()->after('outcome');
            $table->timestamp('status_note_at')->nullable()->after('status_note');
        });
    }

    public function down(): void
    {
        Schema::table('live_tracks', function (Blueprint $table) {
            $table->dropColumn(['crew_key', 'status_note', 'status_note_at']);
        });
    }
};
