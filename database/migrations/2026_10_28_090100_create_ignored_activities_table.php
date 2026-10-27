<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Der Grabstein für gelöschte Aktivitäten, für jede Quelle.
 *
 * `ignored_strava_activities` kannte nur Strava-IDs. Mit Garmin als zweiter
 * Quelle käme eine in Zone3 gelöschte Garmin-Aktivität sonst beim nächsten
 * Push zurück. Die Zeilen werden übernommen, die alte Tabelle fällt weg.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ignored_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 16);
            $table->unsignedBigInteger('external_id');
            $table->timestamps();

            $table->unique(['user_id', 'source', 'external_id'], 'ignored_user_source_external_unique');
        });

        if (Schema::hasTable('ignored_strava_activities')) {
            DB::table('ignored_strava_activities')->orderBy('id')->each(function ($row) {
                DB::table('ignored_activities')->insertOrIgnore([
                    'user_id'     => $row->user_id,
                    'source'      => 'strava',
                    'external_id' => $row->strava_id,
                    'created_at'  => $row->created_at,
                    'updated_at'  => $row->updated_at,
                ]);
            });

            Schema::drop('ignored_strava_activities');
        }
    }

    public function down(): void
    {
        Schema::create('ignored_strava_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('strava_id');
            $table->timestamps();

            $table->unique(['user_id', 'strava_id'], 'ignored_user_strava_unique');
        });

        DB::table('ignored_activities')->where('source', 'strava')->orderBy('id')->each(function ($row) {
            DB::table('ignored_strava_activities')->insert([
                'user_id'    => $row->user_id,
                'strava_id'  => $row->external_id,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        });

        Schema::drop('ignored_activities');
    }
};
