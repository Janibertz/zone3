<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\RacePredictionService;

class Event extends Model
{
    use HasFactory;

    /** Standard Backyard Ultra loop distance in km (4.167 mi). */
    public const BACKYARD_LAP_KM = 6.706;

    /**
     * Rolling training-plan window. Plans only cover the next N days instead of all
     * the way to race day — they get regenerated regularly anyway, so planning months
     * ahead just wastes tokens. The window slides forward (see AutoUpdatePlans) once it
     * covers fewer than REFRESH days ahead; the race day + taper are added once the race
     * falls inside the window.
     */
    public const PLAN_HORIZON_DAYS  = 14;
    public const PLAN_REFRESH_DAYS   = 6;

    protected $appends = ['distance_label', 'target_time_formatted'];

    /**
     * Wie lange eine Planerstellung laufen darf, bevor sie als
     * haengengeblieben gilt.
     *
     * Bewusst ueber dem Job-Timeout von 1800 Sekunden: solange der Job noch
     * laufen koennte, darf kein zweiter gestartet werden — zwei gleichzeitige
     * Laeufe wuerden sich gegenseitig die Plaene unter den Fuessen wegloeschen.
     */
    public const PLAN_GENERATING_STALE_MINUTES = 35;

    /**
     * Laeuft gerade wirklich eine Planerstellung?
     *
     * Ein gesetzter Schalter ohne Zeitstempel stammt aus der Zeit vor dieser
     * Pruefung — also aus einem Lauf, der nie zu Ende kam.
     */
    public function isGeneratingPlan(): bool
    {
        if (! $this->plan_generating) {
            return false;
        }

        return $this->plan_generating_at !== null
            && $this->plan_generating_at->gt(now()->subMinutes(self::PLAN_GENERATING_STALE_MINUTES));
    }

    /** Der Schalter steht, aber niemand rechnet mehr. */
    public function hasStalePlanGeneration(): bool
    {
        return (bool) $this->plan_generating && ! $this->isGeneratingPlan();
    }

    protected $fillable = [
        'user_id',
        'goal_type',
        'open_focus',
        'open_test',
        'block_number',
        'name',
        'event_date',
        'plan_start_date',
        'early_recovery_week',
        'race_distance',
        'distance_km',
        'goal_check_week',
        'goal_confirmed_at',
        'priority',
        'target_time_hours',
        'target_time_minutes',
        'target_yards',
        'plan_generating',
        'plan_generating_at',
        'plan_error',
        'notes',
    ];

    protected $casts = [
        'event_date'          => 'date',
        'open_test'           => 'boolean',
        'block_number'        => 'integer',
        'plan_start_date'     => 'date',
        'early_recovery_week' => 'date',
        'distance_km'         => 'float',
        'goal_confirmed_at'   => 'datetime',
        'target_time_hours'   => 'integer',
        'target_time_minutes' => 'integer',
        'target_yards'        => 'integer',
        'plan_generating'     => 'boolean',
        'plan_generating_at'  => 'datetime',
    ];

    /** Ein Block ohne Rennen dauert zwölf Wochen (Jan, 10.11.2026). */
    public const BLOCK_WEEKS = 12;

    /** Der Testlauf am Blockende: 5 km, so schnell es gleichmäßig geht. */
    public const OPEN_TEST_KM = 5.0;

    /**
     * Ausrichtungen eines Plans ohne Rennen. `distance` ist die Distanz, an
     * der sich Leiter, Gerüst und Prognose orientieren — dieselbe Maschine
     * wie bei einem Rennen, nur ohne Renntag.
     */
    public const OPEN_FOCI = [
        'base'     => ['label' => 'Grundlage aufbauen', 'distance' => '10km'],
        'speed'    => ['label' => 'Schneller werden',   'distance' => '10km'],
        'maintain' => ['label' => 'Form halten',        'distance' => '10km'],
    ];

    /** Nur echte Rennen — ohne Ziele ohne Rennen. */
    public function scopeRaces($query)
    {
        return $query->where('goal_type', '!=', 'open');
    }

    public function isOpen(): bool
    {
        return $this->goal_type === 'open';
    }

    /** Am Blockende steht ein Testlauf — nur dann ist der Tag ein „Rennen". */
    public function hasOpenTest(): bool
    {
        return $this->isOpen() && (bool) $this->open_test;
    }

    public function openFocusLabel(): ?string
    {
        return $this->isOpen() ? (self::OPEN_FOCI[$this->open_focus]['label'] ?? 'Ohne Rennen') : null;
    }

    /**
     * Das Ende des ersten Blocks: der Sonntag nach zwölf Wochen, gezählt ab
     * der Woche des Starts. Ein Start mitten in der Woche macht den ersten
     * Block ein paar Tage kürzer, dafür liegt jeder Testlauf auf einem
     * Sonntag.
     */
    public static function openBlockEnd(\Carbon\CarbonInterface $start): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::parse($start->toDateString())
            ->startOfWeek()
            ->addWeeks(self::BLOCK_WEEKS)
            ->subDay();
    }

    /**
     * Die Felder eines neuen Ziels ohne Rennen — eine Stelle für Onboarding
     * und Rennen-Seite. Beim schneller Werden zählt die gewählte Distanz
     * (5 oder 10 km), sonst orientiert sich alles an 10 km.
     *
     * @return array<string, mixed>
     */
    public static function openAttributes(string $focus, ?string $distance, bool $test, ?string $start = null): array
    {
        $focus    = array_key_exists($focus, self::OPEN_FOCI) ? $focus : 'base';
        $distance = $focus === 'speed' && in_array($distance, ['5km', '10km'], true)
            ? $distance
            : self::OPEN_FOCI[$focus]['distance'];

        $today = \Carbon\CarbonImmutable::today();
        $begin = $start ? \Carbon\CarbonImmutable::parse($start) : $today;
        if ($begin->lt($today)) {
            $begin = $today;
        }

        $label = self::OPEN_FOCI[$focus]['label'];
        if ($focus === 'speed') {
            $label .= ' auf ' . ($distance === '5km' ? '5' : '10') . ' km';
        }

        return [
            'goal_type'           => 'open',
            'open_focus'          => $focus,
            'open_test'           => $test,
            'block_number'        => 1,
            'name'                => $label,
            'race_distance'       => $distance,
            'distance_km'         => null,
            'event_date'          => self::openBlockEnd($begin)->toDateString(),
            'plan_start_date'     => $begin->gt($today) ? $begin->toDateString() : null,
            'priority'            => 'A',
            'target_time_hours'   => 0,
            'target_time_minutes' => 0,
            'target_yards'        => null,
        ];
    }

    /** Montag der ersten Woche des laufenden Blocks. */
    public function openBlockStart(): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::parse($this->event_date->toDateString())
            ->startOfWeek()
            ->subWeeks(self::BLOCK_WEEKS - 1);
    }

    /**
     * Den nächsten Block beginnen, sobald der laufende vorbei ist. Gibt
     * zurück, ob sich etwas geändert hat. Läuft nachts über
     * `plan:roll-blocks` und vor dem Planen, damit ein Plan nie an einem
     * vergangenen Blockende hängen bleibt.
     */
    public function rollOpenBlock(?\Carbon\CarbonInterface $today = null): bool
    {
        if (! $this->isOpen()) {
            return false;
        }

        $today = \Carbon\CarbonImmutable::parse(($today ?? now())->toDateString());
        $end   = \Carbon\CarbonImmutable::parse($this->event_date->toDateString());
        if ($end->gte($today)) {
            return false;
        }

        $blocks = 0;
        while ($end->lt($today)) {
            $end = $end->addWeeks(self::BLOCK_WEEKS);
            $blocks++;
        }

        $this->forceFill([
            'event_date'          => $end->toDateString(),
            'block_number'        => (int) $this->block_number + $blocks,
            'early_recovery_week' => null,
        ])->save();

        return true;
    }

    public function isBackyard(): bool
    {
        return $this->race_distance === 'backyard_ultra';
    }

    /** Total target distance in km derived from the yard goal. */
    public function getTargetDistanceKmAttribute(): ?float
    {
        return $this->isBackyard() && $this->target_yards
            ? round($this->target_yards * self::BACKYARD_LAP_KM, 1)
            : null;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function trainingPlans()
    {
        return $this->hasMany(TrainingPlan::class);
    }

    public function latestPlan()
    {
        return $this->hasOne(TrainingPlan::class)->latestOfMany();
    }

    /**
     * Die Distanz als Zahl, fuer jede Rennart.
     *
     * `distance_label` gibt „24,6 km" oder „Marathon" — gut zum Lesen, nicht
     * zum Rechnen. Sobald ein Rennen im Trainingsplan Platz belegt, muss der
     * Umfang bekannt sein: das Geruest zieht seine Kilometer vom
     * Wochenbudget ab.
     *
     * Die Standarddistanzen kommen aus {@see RacePredictionService::ANCHORS}
     * und werden hier nicht ein zweites Mal hingeschrieben — genau daran ist
     * die Rennprognose schon einmal auseinandergelaufen.
     */
    public function getRaceKmAttribute(): ?float
    {
        if ($this->isBackyard()) {
            return $this->target_distance_km;
        }

        // Ohne Rennen ist der einzige „Renntag" der Testlauf.
        if ($this->isOpen()) {
            return self::OPEN_TEST_KM;
        }

        $anchor = RacePredictionService::ANCHORS[$this->race_distance] ?? null;

        if ($anchor) {
            return (float) $anchor['km'];
        }

        return $this->distance_km ? (float) $this->distance_km : null;
    }

    /**
     * Die angepeilte Zeit in Minuten — oder null, wenn keine gesetzt ist.
     *
     * `target_time_formatted` liest sich gut, rechnet aber nicht. Sobald ein
     * Rennen im Plan steht, muss die Zielzeit rechenbar sein: sie ist die
     * Dauer des Renntages und ergibt zusammen mit der Distanz das Zieltempo.
     *
     * Ohne sie stand am 5-km-Renntag „27 min" — die Schaetzung aus der
     * Zonentabelle — statt der eingetragenen 20 Minuten, und das Modell
     * pacte den 5er im Marathontempo.
     */
    public function getTargetMinutesAttribute(): ?int
    {
        $minutes = ((int) $this->target_time_hours) * 60 + (int) $this->target_time_minutes;

        return $minutes > 0 ? $minutes : null;
    }

    /**
     * Das Zieltempo in Sekunden je Kilometer, aus Zielzeit und Distanz.
     *
     * Bewusst nicht gerundet zurueckgegeben — die Formatierung macht
     * {@see PaceFormat::target()}, und die rundet ABWAERTS. Eine Zielpace
     * weist an, sie beschreibt nicht: aufgerundet verfehlt man das Ziel.
     */
    public function getTargetPaceSecondsAttribute(): ?float
    {
        $minutes = $this->target_minutes;
        $km      = $this->race_km;

        if (! $minutes || ! $km || $km <= 0) {
            return null;
        }

        return $minutes * 60 / $km;
    }

    public function getDistanceLabelAttribute(): string
    {
        if ($this->isOpen()) {
            return $this->openFocusLabel();
        }

        return match ($this->race_distance) {
            '5km'           => '5 km',
            '10km'          => '10 km',
            'half_marathon' => 'Halbmarathon',
            'marathon'      => 'Marathon',
            'backyard_ultra'=> 'Backyard Ultra',
            default         => $this->distance_km ? number_format($this->distance_km, 1, ',', '.') . ' km' : 'Eigene Distanz',
        };
    }

    public function getTargetTimeFormattedAttribute(): ?string
    {
        if ($this->isBackyard()) {
            if (! $this->target_yards) return null;
            $km = number_format($this->target_distance_km, 1, ',', '.');
            return "{$this->target_yards} Std (≈ {$km} km)";
        }

        $h = $this->target_time_hours;
        $m = $this->target_time_minutes;
        if ($h === 0 && $m === 0) return null;
        return $h > 0 ? sprintf('%d:%02d Std', $h, $m) : "{$m} Min";
    }

    public function getDaysUntilAttribute(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->event_date->copy()->startOfDay(), false);
    }

    /**
     * Der erste Tag, den ein Plan belegen darf: heute, oder das Startdatum,
     * wenn der Athlet den Plan später beginnen lässt.
     *
     * Die eine Stelle, an der das entschieden wird. Gerüst, Prompt und
     * `plan:auto-update` rechnen ihr Fenster von hier aus — rechnete eines
     * davon weiter ab heute, sähe es vor dem Start eine Lücke und baute
     * Einheiten hinein.
     */
    public function planStartsOn(): \Carbon\CarbonImmutable
    {
        $today = \Carbon\CarbonImmutable::today();

        if (! $this->plan_start_date) {
            return $today;
        }

        $start = \Carbon\CarbonImmutable::parse($this->plan_start_date->toDateString());

        return $start->gt($today) ? $start : $today;
    }

    /** Tage vom Planstart bis zum Rennen — `days_until`, nur ab dem Start gezählt. */
    public function daysFromPlanStart(): int
    {
        return (int) $this->planStartsOn()->diffInDays(\Carbon\CarbonImmutable::parse($this->event_date->toDateString()), false);
    }

    /**
     * Die Phase hängt an der Distanz, nicht nur an den Tagen bis zum Rennen.
     *
     * Vorher galt für jedes Rennen: ≤ 14 Tage „Race Week", ≤ 28 „Taper".
     * Beim Halbmarathon setzt die Leiter den längsten Lauf aber genau
     * 14 Tage vorher — der Prompt bekam für denselben Tag „längster Lauf"
     * und „kein Umfang mehr". Jetzt beginnt der Taper dort, wo die Leiter
     * ihn beginnen lässt, und die Rennwoche ist die letzte Woche.
     *
     * Die Grenzen stehen in {@see \App\Services\PeriodizationService},
     * dort rechnet auch der Plan.
     */
    public function getTrainingPhaseAttribute(): array
    {
        return app(\App\Services\PeriodizationService::class)->phaseOn($this);
    }

    public function getWeeksUntilAttribute(): int
    {
        return max(0, (int) ceil($this->days_until / 7));
    }
}
