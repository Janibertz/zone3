<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Wiki und Changelog sind mit dem Admin-Redesign (18.11.2026) entfernt
 * worden (Jan). Die Tabellen fallen mit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('wiki_changelogs');
        Schema::dropIfExists('wiki_pages');
    }

    public function down(): void
    {
        // Kein Weg zurück: Wiki und Changelog gibt es nicht mehr.
    }
};
