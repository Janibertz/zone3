<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Activity;

class TrainingSession extends Model
{
    use HasFactory;

    /**
     * Die deutschen Bezeichnungen der Einheitentypen.
     *
     * Lag bisher als private Methode im Review-Job und in zwei Varianten im
     * Chat-Service — mit unterschiedlichem Umfang, weil neue Typen nur an
     * einer der Stellen nachgetragen wurden.
     */
    public const TYPE_LABELS = [
        'easy_run'          => 'Lockerer Lauf',
        'tempo_run'         => 'Tempolauf',
        'interval'          => 'Intervalltraining',
        'long_run'          => 'Langer Lauf',
        'progressive_run'   => 'Progressiver Lauf',
        'test_run'          => 'Testlauf',
        'race_prep'         => 'Rennvorbereitung',
        'back_to_back_long' => 'Back-to-Back Longrun',
        'time_on_feet'      => 'Time on Feet',
        'night_run'         => 'Nachtlauf',
        'yard_simulation'   => 'Yard-Simulation',
        'strength'          => 'Krafttraining',
        'core'              => 'Core-Training',
        'mobility'          => 'Mobility',
        'rest'              => 'Ruhetag',
    ];

    /**
     * Sportarten, wie Strava sie liefert.
     *
     * NULL heisst Laufen — der Normalfall und alles, was aus dem Plan stammt.
     * Nur importierte Fremdsportarten tragen hier einen Wert.
     */
    public const SPORT_LABELS = [
        'Run'            => 'Lauf',
        'TrailRun'       => 'Trailrun',
        'VirtualRun'     => 'Laufband',
        'Swim'           => 'Schwimmen',
        'Ride'           => 'Radfahren',
        'VirtualRide'    => 'Indoor-Radfahren',
        'GravelRide'     => 'Gravelbike',
        'MountainBikeRide' => 'Mountainbike',
        'EBikeRide'      => 'E-Bike',
        'Walk'           => 'Spaziergang',
        'Hike'           => 'Wanderung',
        'WeightTraining' => 'Krafttraining',
        'Workout'        => 'Workout',
        'Yoga'           => 'Yoga',
        'Rowing'         => 'Rudern',
        'Elliptical'     => 'Crosstrainer',
        'StairStepper'   => 'Stepper',
        'Crossfit'       => 'CrossFit',
        'AlpineSki'      => 'Ski',
        'NordicSki'      => 'Langlauf',
        'Snowboard'      => 'Snowboard',
        'Skating'        => 'Inline-Skating',
        'Golf'           => 'Golf',
        'Soccer'         => 'Fussball',
        'Tennis'         => 'Tennis',
        'Badminton'      => 'Badminton',
    ];

    /** Sportarten, deren Pace und Distanz mit dem Laufen vergleichbar sind. */
    public const RUN_SPORTS = ['Run', 'TrailRun', 'VirtualRun'];

    /** Die Sportart im Klartext. Ohne Angabe: Laufen. */
    public function sportLabel(): string
    {
        if ($this->sport_type === null) {
            return 'Lauf';
        }

        return self::SPORT_LABELS[$this->sport_type] ?? $this->sport_type;
    }

    /** Zaehlt diese Einheit in Wochenkilometer und Pace-Vergleiche? */
    public function isRun(): bool
    {
        return $this->sport_type === null
            || in_array($this->sport_type, self::RUN_SPORTS, true);
    }

    protected $fillable = [
        'user_id',
        'training_plan_id',
        'event_id',
        'activity_id',
        'planned_date',
        'type',
        'sport_type',
        'title',
        'description',
        'distance_km',
        'duration_min',
        'pace_target',
        'zone',
        'planned_snapshot',
        'was_unplanned',
        'pinned_at',
        'intensity',
        'status',
        'skip_reason',
        'sort_order',
        'rating',
        'effort_perceived',
        'feeling_notes',
        'coach_review',
        'review_question',
        'review_options',
        'review_feedback',
        'reviewed_at',
        'nutrition_tips',
        'steps',
        'exercises',
    ];

    protected $casts = [
        'planned_date'     => 'date',
        'distance_km'      => 'float',
        'zone'             => 'integer',
        'planned_snapshot' => 'array',
        'was_unplanned'    => 'boolean',
        'pinned_at'        => 'datetime',
        'sort_order'       => 'integer',
        'rating'           => 'integer',
        'effort_perceived' => 'integer',
        'review_options'   => 'array',
        'reviewed_at'      => 'datetime',
        'nutrition_tips'   => 'array',
        'steps'            => 'array',
        'exercises'        => 'array',
    ];

    public function user()        { return $this->belongsTo(User::class); }
    public function trainingPlan(){ return $this->belongsTo(TrainingPlan::class); }
    public function event()       { return $this->belongsTo(Event::class); }
    public function activity()    { return $this->belongsTo(Activity::class); }
}
