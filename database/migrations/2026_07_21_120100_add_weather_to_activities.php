<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            // Weather snapshot at training time (temp_c, apparent_c, wind_kmh, precip_mm, code, description).
            // Filled once at import via the Open-Meteo archive lookup; degrades to null.
            $table->json('weather')->nullable()->after('polyline');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('weather');
        });
    }
};
