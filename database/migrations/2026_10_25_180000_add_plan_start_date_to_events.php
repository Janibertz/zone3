<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ab wann der Plan beginnt. Null heisst: sofort.
 *
 * Gewuenscht: „Ich möchte zum Beispiel erst den Plan später starten … Es soll
 * nichts im Plan vor dem Startdatum stehen." Das Datum gehoert ans Event und
 * nicht an den Plan — der Plan wird erst gebaut, nachdem es feststeht, und
 * jede spaetere Neuberechnung muss es ebenso kennen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->date('plan_start_date')->nullable()->after('event_date');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('plan_start_date');
        });
    }
};
