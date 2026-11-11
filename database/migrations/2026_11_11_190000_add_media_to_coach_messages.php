<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprachnachrichten und Bilder an den Coach (Telegram, 11.11.2026).
 *
 * Gespeichert wird nie die Datei, nur was daraus geworden ist:
 *
 *  - `kind`        `voice` oder `image`, null = getippt
 *  - `media_text`  bei einem Bild die Beschreibung des Bildmodells. Sie steht
 *                  getrennt vom `content` (den Worten des Athleten), weil sie
 *                  Fremdtext ist: was auf einem Bild steht, hat der Athlet
 *                  nicht geschrieben, und der Coach darf es nie als Auftrag
 *                  lesen.
 *  - `media_foreign` die Nachricht war weitergeleitet — dann ist auch eine
 *                  Abschrift Fremdtext.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coach_messages', function (Blueprint $table) {
            $table->string('kind', 16)->nullable()->after('channel');
            $table->text('media_text')->nullable()->after('kind');
            $table->boolean('media_foreign')->default(false)->after('media_text');
        });
    }

    public function down(): void
    {
        Schema::table('coach_messages', function (Blueprint $table) {
            $table->dropColumn(['kind', 'media_text', 'media_foreign']);
        });
    }
};
