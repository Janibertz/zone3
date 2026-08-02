<?php

namespace App\Console\Commands;

use App\Models\LiveTrack;
use App\Services\LiveTrackService;
use Illuminate\Console\Command;

/**
 * Fragt laufende LiveTrack-Sitzungen ab und legt das Ergebnis in der
 * Datenbank ab. Die öffentliche Seite liest nur diesen Stand — dadurch
 * sieht Garmin genau einen Abruf pro Minute, egal wie viele Zuschauer
 * gerade zuschauen.
 */
class PollLiveTracks extends Command
{
    protected $signature   = 'livetrack:poll {--id= : Nur diese LiveTrack-ID}';
    protected $description = 'Holt aktuelle Werte laufender LiveTrack-Sitzungen';

    public function handle(LiveTrackService $service): int
    {
        $query = LiveTrack::query()
            ->where('is_active', true)
            ->whereNotNull('garmin_session_id');

        if ($id = $this->option('id')) {
            $query->where('id', $id);
        } else {
            // Ab einer Stunde vor dem Start bis 48 Stunden danach — ein
            // Backyard laeuft lange, aber nicht ewig.
            $query->where('starts_at', '<=', now()->addHour())
                  ->where('starts_at', '>=', now()->subHours(48));
        }

        $tracks = $query->get();

        if ($tracks->isEmpty()) {
            $this->info('Keine aktive Sitzung.');
            return self::SUCCESS;
        }

        foreach ($tracks as $track) {
            $ok = $service->poll($track);
            $this->line(sprintf(
                '%s [%s] %s',
                $ok ? 'OK  ' : 'FEHL',
                $track->slug,
                $ok ? round((data_get($track->state, 'distanceM') ?? 0) / 1000, 2) . ' km' : $track->last_error
            ));
        }

        return self::SUCCESS;
    }
}
