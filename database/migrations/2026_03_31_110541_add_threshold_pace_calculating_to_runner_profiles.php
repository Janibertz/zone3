<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table) {
            $table->boolean('threshold_pace_calculating')->default(false)->after('threshold_pace_history');
        });
    }

    public function down(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table) {
            $table->dropColumn('threshold_pace_calculating');
        });
    }
};
