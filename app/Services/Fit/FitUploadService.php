<?php

namespace App\Services\Fit;

use App\Models\Activity;
use App\Models\IgnoredActivity;
use App\Models\User;
use App\Services\ActivityImportService;
use App\Services\BestEffortService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Eine FIT-Datei von Hand hochladen (Jan, 08.11.2026).
 *
 * Für alles, was keine Quelle liefert: eine Zwift-Fahrt ohne Strava, eine
 * Aktivität aus einem Garmin-Connect-Export, eine Uhr ohne Anbindung. Die
 * Datei läuft durch denselben Leser wie COROS (`FitDecoder`) und dieselbe
 * Übersetzung (`FitActivityAttributes`), danach durch den gemeinsamen
 * Import — gibt es dieselbe Einheit schon aus einer Quelle, wird nichts
 * doppelt angelegt.
 *
 * Kennung der Datei ist ihre Startzeit (`activities.file_start`); dieselbe
 * Datei zweimal hochgeladen frischt nur auf.
 */
class FitUploadService
{
    public const RESULT_IMPORTED = 'imported';
    public const RESULT_KNOWN = 'known';
    public const RESULT_TOMBSTONED = 'tombstoned';
    public const RESULT_UNREADABLE = 'unreadable';

    public function __construct(
        private readonly FitDecoder $fit,
        private readonly ActivityImportService $activities,
        private readonly BestEffortService $bestEfforts,
    ) {}

    /**
     * @return array{result: string, activity: ?Activity}
     */
    public function import(User $user, string $bytes): array
    {
        try {
            $decoded = $this->fit->decode($bytes);
        } catch (Throwable $e) {
            Log::info('FIT-Upload nicht lesbar', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return ['result' => self::RESULT_UNREADABLE, 'activity' => null];
        }

        $session = $decoded['session'] ?? [];
        $start   = $session['start_time'] ?? ($decoded['records'][0]['timestamp'] ?? null);

        if (! $start || ($session === [] && $decoded['records'] === [])) {
            return ['result' => self::RESULT_UNREADABLE, 'activity' => null];
        }

        $start = (int) $start;

        if (IgnoredActivity::blocks($user->id, Activity::SOURCE_FILE, $start)) {
            return ['result' => self::RESULT_TOMBSTONED, 'activity' => null];
        }

        $type       = FitSport::toInternal($session['sport'] ?? null, $session['sub_sport'] ?? null);
        $attributes = array_merge([
            'name'          => FitSport::defaultName($type),
            'type'          => $type,
            // Wie bei Strava und Garmin die UTC-Uhrzeit, sonst findet der
            // Abgleich dieselbe Einheit aus einer Quelle nicht.
            'start_date'    => Carbon::createFromTimestampUTC($start),
            'distance'      => 0,
            'moving_time'   => 0,
            'elapsed_time'  => 0,
            'average_speed' => 0,
        ], FitActivityAttributes::fromDecoded($decoded, Activity::SOURCE_FILE));

        $activity = $this->activities->store($user->id, Activity::SOURCE_FILE, $start, $attributes);

        if (! $activity->wasRecentlyCreated) {
            return ['result' => self::RESULT_KNOWN, 'activity' => $activity];
        }

        $efforts = BestEffortService::effortsFromStream(FitActivityAttributes::stream($decoded['records']));

        $this->activities->processNew(
            $activity,
            fn (Activity $a) => $this->bestEfforts->syncFromActivityData($a, ['best_efforts' => $efforts]),
            notify: false,
        );
        $this->activities->dispatchPlanRegenerationIfNeeded($user->id);

        Log::info('FIT-Datei hochgeladen', [
            'user_id'     => $user->id,
            'activity_id' => $activity->id,
            'type'        => $activity->type,
        ]);

        return ['result' => self::RESULT_IMPORTED, 'activity' => $activity];
    }
}
