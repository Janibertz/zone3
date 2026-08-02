<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der zusaetzliche Status (finished/dnf) ist entfallen: die endgueltige
 * Rundenzahl allein sagt, dass das Rennen vorbei ist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_tracks', function (Blueprint $table) {
            $table->dropColumn('outcome');
        });
    }

    public function down(): void
    {
        Schema::table('live_tracks', function (Blueprint $table) {
            $table->string('outcome', 20)->nullable();
        });
    }
};
