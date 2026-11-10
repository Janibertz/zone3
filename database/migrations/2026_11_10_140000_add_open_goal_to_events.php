<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan ohne Rennen (10.11.2026, Jan): ein Ziel ohne Termin läuft in Blöcken
 * zu zwölf Wochen. `event_date` ist das Ende des laufenden Blocks (der Tag
 * des Testlaufs), `block_number` zählt die Blöcke, `open_focus` ist die
 * Ausrichtung, `open_test` sagt, ob am Blockende ein 5-km-Test steht.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('goal_type', 10)->default('race')->after('user_id');
            $table->string('open_focus', 12)->nullable()->after('goal_type');
            $table->boolean('open_test')->default(true)->after('open_focus');
            $table->unsignedSmallInteger('block_number')->default(1)->after('open_test');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['goal_type', 'open_focus', 'open_test', 'block_number']);
        });
    }
};
