<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eine Aktivität kann aus Strava, aus Garmin oder aus beiden kommen.
 *
 * Zwei ID-Spalten statt einer generischen `external_id`, weil eine Zeile
 * beide tragen kann: kommt dieselbe Einheit über den zweiten Weg, hängt der
 * Import die zweite ID an die bestehende Zeile, statt sie doppelt anzulegen.
 * `source` sagt, wessen Zahlen in der Zeile stehen. Der Bestand ist Strava.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->bigInteger('strava_id')->nullable()->change();
            $table->unsignedBigInteger('garmin_activity_id')->nullable()->unique()->after('strava_id');
            $table->string('source', 16)->default('strava')->after('garmin_activity_id');
            $table->string('device_name')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropUnique(['garmin_activity_id']);
            $table->dropColumn(['garmin_activity_id', 'source', 'device_name']);
        });
    }
};
