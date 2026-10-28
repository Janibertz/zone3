<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Was ein Aufruf wirklich gekostet hat.
 *
 * Gecachte Eingabe kostet ein Zehntel, Reasoning wird als Ausgabe bezahlt,
 * Flex die Haelfte. Ohne diese drei Spalten laesst sich keine Sparmassnahme
 * nachmessen — die Euro-Zahl allein sagt nicht, woher sie kommt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_logs', function (Blueprint $table) {
            $table->unsignedInteger('cached_tokens')->default(0)->after('total_tokens');
            $table->unsignedInteger('reasoning_tokens')->default(0)->after('cached_tokens');
            $table->string('service_tier', 20)->nullable()->after('reasoning_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('ai_logs', function (Blueprint $table) {
            $table->dropColumn(['cached_tokens', 'reasoning_tokens', 'service_tier']);
        });
    }
};
