<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    use HasFactory;

    public const SOURCE_STRAVA = 'strava';
    public const SOURCE_GARMIN = 'garmin';
    public const SOURCE_COROS = 'coros';
    /** Von Hand hochgeladene FIT-Datei (Zwift, Garmin-Connect-Export …). */
    public const SOURCE_FILE = 'file';
    /** Trainings aus Apple Health, gelesen von der iOS-App (08.11.2026). */
    public const SOURCE_APPLE = 'apple';
    /** Polar AccessLink (13.11.2026). Kennung = Startzeit, Polars ID ist ein Hash-String. */
    public const SOURCE_POLAR = 'polar';
    /** WHOOP (13.11.2026). Kennung = Startzeit, WHOOPs ID ist eine UUID (`whoop_workout_id`). */
    public const SOURCE_WHOOP = 'whoop';
    /**
     * intervals.icu (15.11.2026), nur Zwift-Fahrten. Kennung = Startzeit,
     * intervals' ID ist ein String (`intervals_activity_id`). Keine wählbare
     * Aktivitätsquelle: Zwift-Fahrten kommen immer, wie bei Strava.
     */
    public const SOURCE_INTERVALS = 'intervals';
    /**
     * ROUVY (18.11.2026), Fahrten aus ROUVY. Kennung = Startzeit, ROUVYs ID
     * ist ein String (`rouvy_activity_id`). Keine wählbare Aktivitätsquelle:
     * ROUVY-Fahrten kommen immer, wie Zwift über intervals.icu.
     */
    public const SOURCE_ROUVY = 'rouvy';

    /** Je Quelle die Spalte mit ihrer ID. Eine Zeile kann beide tragen. */
    public const ID_COLUMNS = [
        self::SOURCE_STRAVA => 'strava_id',
        self::SOURCE_GARMIN => 'garmin_activity_id',
        self::SOURCE_COROS  => 'coros_label_id',
        self::SOURCE_FILE   => 'file_start',
        self::SOURCE_APPLE  => 'apple_start',
        self::SOURCE_POLAR  => 'polar_start',
        self::SOURCE_WHOOP  => 'whoop_start',
        self::SOURCE_INTERVALS => 'intervals_start',
        self::SOURCE_ROUVY  => 'rouvy_start',
    ];

    /** Wie die Quelle in der Oberfläche heißt. */
    public const SOURCE_LABELS = [
        self::SOURCE_STRAVA => 'Strava',
        self::SOURCE_GARMIN => 'Garmin',
        self::SOURCE_COROS  => 'COROS',
        self::SOURCE_FILE   => 'FIT-Datei',
        self::SOURCE_APPLE  => 'Apple Health',
        self::SOURCE_POLAR  => 'Polar',
        self::SOURCE_WHOOP  => 'WHOOP',
        self::SOURCE_INTERVALS => 'intervals.icu',
        self::SOURCE_ROUVY  => 'ROUVY',
    ];

    /**
     * Sportfamilien für den Duplikat-Abgleich: ein Laufband-Lauf bei Garmin
     * und ein „Run“ bei Strava sind dieselbe Einheit, ein Lauf und eine
     * Fahrt zur selben Zeit nicht.
     */
    private const FAMILIES = [
        'run'  => ['Run', 'TrailRun', 'VirtualRun'],
        'ride' => ['Ride', 'VirtualRide', 'EBikeRide', 'MountainBikeRide', 'GravelRide'],
        'swim' => ['Swim'],
    ];

    protected $fillable = [
        'user_id',
        'strava_id',
        'garmin_activity_id',
        'coros_label_id',
        'file_start',
        'apple_start',
        'polar_start',
        'whoop_start',
        'whoop_workout_id',
        'intervals_start',
        'intervals_activity_id',
        'rouvy_start',
        'rouvy_activity_id',
        'source',
        'device_name',
        'name',
        'description',
        'type',
        'distance',
        'moving_time',
        'elapsed_time',
        'total_elevation_gain',
        'average_speed',
        'average_watts',
        'weighted_average_watts',
        'device_watts',
        'laps',
        'strength_sets',
        'max_speed',
        'average_heartrate',
        'max_heartrate',
        'start_date',
        'location_city',
        'location_state',
        'location_country',
        'polyline',
        'weather',
        'best_efforts_synced_at',
        'start_lat',
        'start_lng',
    ];

    protected $casts = [
        'start_date'             => 'datetime',
        'polyline'               => 'array',
        'laps'                   => 'array',
        'strength_sets'          => 'array',
        'weather'                => 'array',
        'best_efforts_synced_at' => 'datetime',
        'start_lat'              => 'float',
        'start_lng'              => 'float',
        'device_watts'           => 'boolean',
    ];

    /**
     * All columns except the heavy `polyline` / `laps` JSON blobs.
     * Use for list/summary queries where the route map is not rendered,
     * to avoid loading (and serializing) large GPS payloads.
     */
    public const SUMMARY_COLUMNS = [
        'id', 'user_id', 'strava_id', 'garmin_activity_id', 'coros_label_id', 'source', 'name', 'description', 'type',
        'distance', 'moving_time', 'elapsed_time', 'total_elevation_gain',
        'average_speed', 'average_watts', 'weighted_average_watts', 'device_watts', 'max_speed', 'average_heartrate',
        'start_date', 'location_city', 'location_state', 'location_country',
        'created_at', 'updated_at',
    ];

    public static function sportFamily(?string $type): string
    {
        foreach (self::FAMILIES as $family => $types) {
            if (in_array($type, $types, true)) {
                return $family;
            }
        }

        return 'other:' . $type;
    }

    /**
     * `start_date` steht in der Zeitzone der App (Europe/Berlin), wie jedes
     * andere Datum in Zone3. Bis zum 22.11.2026 stand hier die UTC-Uhrzeit,
     * gelesen wurde sie aber als Berliner Zeit: Jans Lauf um 9:44 zeigte
     * 8:44, und kurz nach Mitternacht zählte ein Lauf zum Vortag. Jede Quelle
     * liefert einen Zeitpunkt mit Zone (Strava `…Z`, sonst UTC-Sekunden);
     * hier wird er einmal umgerechnet. Ein Text ohne Zone gilt als Berliner
     * Zeit.
     */
    protected function startDate(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if ($value === null || $value === '') {
                    return null;
                }

                $date = match (true) {
                    $value instanceof DateTimeInterface => CarbonImmutable::instance($value),
                    is_numeric($value)                  => CarbonImmutable::createFromTimestampUTC((int) $value),
                    default                             => CarbonImmutable::parse($value),
                };

                return $date->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
            },
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function bestEfforts()
    {
        return $this->hasMany(BestEffort::class);
    }

    /**
     * Woher die Zeile kam, als Name für die Oberfläche. Die Planseite schrieb
     * bis zum 30.10.2026 fest „aus Strava“ — auch unter Garmin-Läufen.
     */
    public function sourceLabel(): string
    {
        return self::SOURCE_LABELS[$this->source] ?? self::SOURCE_LABELS[self::SOURCE_STRAVA];
    }
}
