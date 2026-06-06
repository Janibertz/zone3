<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the athlete dismissed the current return-to-run phase, so the
     * dashboard card stays hidden until a new break/injury starts.
     */
    public function up(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table) {
            $table->dateTime('return_to_run_dismissed_at')->nullable()->after('coach_notes');
        });
    }

    public function down(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table) {
            $table->dropColumn('return_to_run_dismissed_at');
        });
    }
};
