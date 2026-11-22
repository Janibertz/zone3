<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `activities.start_date` stand in UTC und wurde als Berliner Zeit gelesen
 * (Lauf um 9:44 → angezeigt 8:44). Ab jetzt steht dort die Berliner Zeit
 * (Activity::startDate()); die vorhandenen Zeilen werden einmal umgerechnet,
 * je Zeile mit der Sommer-/Winterzeit ihres Tages.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->shift('UTC', config('app.timezone'));
    }

    public function down(): void
    {
        $this->shift(config('app.timezone'), 'UTC');
    }

    private function shift(string $from, string $to): void
    {
        DB::table('activities')
            ->whereNotNull('start_date')
            ->select(['id', 'start_date'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($from, $to) {
                foreach ($rows as $row) {
                    $local = CarbonImmutable::parse($row->start_date, $from)
                        ->setTimezone($to)
                        ->format('Y-m-d H:i:s');

                    DB::table('activities')->where('id', $row->id)->update(['start_date' => $local]);
                }
            });
    }
};
