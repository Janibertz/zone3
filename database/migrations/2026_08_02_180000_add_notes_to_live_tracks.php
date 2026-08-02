<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Aus der einen Lagemeldung wird ein Ticker: mehrere Meldungen mit
 * Zeitstempel, neueste zuerst.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_tracks', function (Blueprint $table) {
            $table->json('notes')->nullable()->after('outcome');
        });

        // Vorhandene Einzelmeldung als erste Ticker-Meldung uebernehmen.
        DB::table('live_tracks')
            ->whereNotNull('status_note')
            ->get(['id', 'status_note', 'status_note_at'])
            ->each(function ($row) {
                DB::table('live_tracks')->where('id', $row->id)->update([
                    'notes' => json_encode([[
                        'id'   => (string) Str::uuid(),
                        'at'   => $row->status_note_at ?? now()->toIso8601String(),
                        'text' => $row->status_note,
                    ]]),
                ]);
            });

        Schema::table('live_tracks', function (Blueprint $table) {
            $table->dropColumn(['status_note', 'status_note_at']);
        });
    }

    public function down(): void
    {
        Schema::table('live_tracks', function (Blueprint $table) {
            $table->string('status_note', 140)->nullable();
            $table->timestamp('status_note_at')->nullable();
            $table->dropColumn('notes');
        });
    }
};
