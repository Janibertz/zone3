<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clear cached step structures for yard simulations.
 *
 * These were previously generated through the generic interval prompt, which produced
 * nonsensical structures (e.g. "1-min loops", duplicated interval blocks). Nulling the
 * cache forces them to be rebuilt by the deterministic hourly-rhythm builder on next view.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('training_sessions')
            ->where('type', 'yard_simulation')
            ->whereNotNull('steps')
            ->update(['steps' => null]);
    }

    public function down(): void
    {
        // Cache only — nothing to restore.
    }
};
