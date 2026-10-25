<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\PaceFormat;
use App\Services\Vdot;
use App\Services\VdotService;

class RunnerProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'threshold_heart_rate',
        'max_heart_rate',
        'threshold_speed',
        'heart_rate_zones',
        'pace_zones',
        'has_completed_setup',
        'today_recommendation',
        'recommendation_date',
        'recommendation_wellbeing_id',
        'threshold_pace_calculated_at',
        'threshold_pace_history',
        'threshold_pace_calculating',
        'weekly_availability',
        'week_check_week',
        'strength_enabled',
        'strength_days_per_week',
        'strength_equipment',
        'strength_experience',
        'daily_message',
        'daily_message_date',
        'pending_pr_activity_id',
        'pending_pr_message',
        'coach_notes',
        'return_to_run_dismissed_at',
        'training_paused_until',
        'training_pause_reason',
        'ftp',
        'training_model',
        'training_model_next',
        'training_model_next_from',
        'running_experience_years',
        'has_lactate_meter',
        'bike_enabled',
        'bike_season',
        'bike_purpose',
        'bike_max_per_week',
        'bike_preferred_days',
    ];

    /** Polarisiert nach Seiler/Daniels oder norwegisch mit Sub-Schwelle. */
    public const TRAINING_MODELS = ['polarized', 'norwegian'];

    /** Rad nur im Winter (November bis März) oder das ganze Jahr. */
    public const BIKE_SEASONS = ['winter', 'all_year'];

    /** Lockere Läufe ersetzen, eigene Radintervalle, oder beides. */
    public const BIKE_PURPOSES = ['easy', 'intervals', 'both'];

    protected $casts = [
        'heart_rate_zones' => 'array',
        'pace_zones' => 'array',
        'threshold_pace_history' => 'array',
        'has_completed_setup' => 'boolean',
        'threshold_pace_calculated_at' => 'datetime',
        'threshold_pace_calculating' => 'boolean',
        'weekly_availability'        => 'array',
        'strength_enabled'           => 'boolean',
        'strength_days_per_week'     => 'integer',
        'strength_equipment'         => 'array',
        'return_to_run_dismissed_at' => 'datetime',
        'training_paused_until'      => 'date',
        'training_model_next_from'   => 'date',
        'has_lactate_meter'          => 'boolean',
        'bike_enabled'               => 'boolean',
        'bike_max_per_week'          => 'integer',
        'bike_preferred_days'        => 'array',
        'running_experience_years'   => 'integer',
        'ftp'                        => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Calculate heart rate zones based on threshold HR
     */
    public function calculateHeartRateZones(): array
    {
        if (!$this->threshold_heart_rate || !$this->max_heart_rate) {
            return [];
        }

        $lthr = $this->threshold_heart_rate;
        $maxhr = $this->max_heart_rate;
        $hrr = $maxhr - 60; // Heart Rate Reserve

        return [
            'z1' => ['name' => 'Zone 1 (Recovery)', 'min' => 60, 'max' => (int)($lthr * 0.81)],
            'z2' => ['name' => 'Zone 2 (Aerobic)', 'min' => (int)($lthr * 0.81), 'max' => $lthr],
            'z3' => ['name' => 'Zone 3 (Tempo)', 'min' => $lthr, 'max' => (int)($lthr * 1.06)],
            'z4' => ['name' => 'Zone 4 (Threshold)', 'min' => (int)($lthr * 1.06), 'max' => (int)($lthr * 1.15)],
            'z5' => ['name' => 'Zone 5 (VO2 Max)', 'min' => (int)($lthr * 1.15), 'max' => $maxhr],
        ];
    }

    /** @var array{key: string, value: array|null}|null */
    private ?array $vdotMemo = null;

    /**
     * Der VDOT mit seiner Herkunft — siehe {@see VdotService}.
     *
     * Pro Instanz gemerkt, solange sich die Schwellenpace nicht ändert:
     * Zonen, Prognose und Plan fragen ihn innerhalb eines Requests mehrfach.
     *
     * @return array{value: float, source: string, label: string, from_threshold: float|null, from_effort: float|null}|null
     */
    public function vdotInfo(): ?array
    {
        $key = (string) $this->threshold_speed;

        if ($this->vdotMemo === null || $this->vdotMemo['key'] !== $key) {
            $this->vdotMemo = ['key' => $key, 'value' => app(VdotService::class)->forProfile($this)];
        }

        return $this->vdotMemo['value'];
    }

    public function vdot(): ?float
    {
        return $this->vdotInfo()['value'] ?? null;
    }

    /**
     * Die Pace-Zonen kommen aus dem VDOT und werden nicht mehr gespeichert.
     *
     * Die Spalte `pace_zones` wurde an drei Stellen geschrieben (Schwellen-
     * Job, Onboarding, Profil) und an fünf gelesen. Mit den Bestzeiten als
     * zweiter VDOT-Quelle wäre sie nach jedem schnellen Lauf veraltet. Der
     * gespeicherte Wert zählt nur noch, solange es keinen VDOT gibt.
     */
    public function getPaceZonesAttribute($value): ?array
    {
        $zones = $this->calculatePaceZones();

        return $zones ?: ($value ? json_decode($value, true) : null);
    }

    /**
     * Die fünf Pace-Zonen, Grenzen als Anteil am VO2max aus {@see Vdot::ZONES}.
     *
     * Vorher feste Abstände zur Schwellenpace (Z2 = T + 0:46 bis + 1:21),
     * die für einen 3:30-Läufer etwas anderes bedeuteten als für einen
     * 6:00-Läufer.
     */
    public function calculatePaceZones(): array
    {
        $vdot = $this->vdot();

        if (! $vdot) {
            return [];
        }

        $names = [1 => 'Zone 1 (Recovery)', 2 => 'Zone 2 (Easy)', 3 => 'Zone 3 (Tempo)', 4 => 'Zone 4 (Threshold)', 5 => 'Zone 5 (VO2 Max)'];
        $zones = [];

        foreach (Vdot::ZONES as $zone => [$fast, $slow]) {
            $zones["z{$zone}"] = [
                'name'     => $names[$zone],
                'min_pace' => $fast !== null ? PaceFormat::fromSeconds(Vdot::paceAt($vdot, $fast)) : '0:00',
                'max_pace' => $slow !== null ? PaceFormat::fromSeconds(Vdot::paceAt($vdot, $slow)) : '∞',
            ];
        }

        return $zones;
    }

    /**
     * Läuft an diesem Tag eine Trainingspause?
     *
     * Gemeldet: krank eingetragen, im Coach-Chat „zwei Wochen Laufpause nach
     * einer Weisheitszahn-OP" gesagt — und die Tagesempfehlung blieb beim
     * lockeren Lauf. Der Coach hatte sich die Pause nur als Notiz gemerkt,
     * und keine Notiz wirkt auf einen Plan. Die Pause steht deshalb als
     * Datum im Profil, und Tagesempfehlung, Gerüst und Telegram fragen hier.
     */
    public function isPausedOn(\Carbon\CarbonInterface|string|null $day = null): bool
    {
        if (! $this->training_paused_until) {
            return false;
        }

        $day = $day ? \Carbon\Carbon::parse($day) : now();

        return $day->startOfDay()->lte($this->training_paused_until->copy()->startOfDay());
    }

    /**
     * Das Trainingsmodell, das an einem Tag gilt.
     *
     * Ein Wechsel wartet bis zum nächsten Wochenplan (Montag nach dem
     * Speichern) — bis dahin plant jede Neuberechnung mit dem alten Modell
     * weiter. Ab dem Montag gilt das neue, auch wenn niemand es „umgelegt"
     * hat: das Datum entscheidet, nicht ein Kommando.
     */
    public function trainingModelOn(\Carbon\CarbonInterface|string|null $day = null): string
    {
        $day = $day ? \Carbon\Carbon::parse($day) : now();

        if ($this->training_model_next && $this->training_model_next_from
            && $day->startOfDay()->gte($this->training_model_next_from->copy()->startOfDay())) {
            return $this->training_model_next;
        }

        return $this->training_model ?: 'polarized';
    }

    /**
     * Einen fälligen Wechsel festschreiben. Nur fürs Aufräumen — wer das
     * gültige Modell wissen will, fragt {@see trainingModelOn()}, und das
     * stimmt auch ohne diesen Aufruf.
     */
    public function settlePendingTrainingModel(): void
    {
        if ($this->training_model_next && $this->training_model_next_from
            && now()->startOfDay()->gte($this->training_model_next_from->copy()->startOfDay())) {
            $this->forceFill([
                'training_model'           => $this->training_model_next,
                'training_model_next'      => null,
                'training_model_next_from' => null,
            ])->save();
        }
    }

    /**
     * Ein neues Modell wählen. Ohne laufenden Plan gilt es sofort (im
     * Onboarding gibt es keine Woche, die umgeworfen werden könnte), sonst
     * ab dem nächsten Montag. Wer zurück zum gerade gültigen Modell wechselt,
     * nimmt den offenen Wechsel zurück.
     */
    public function chooseTrainingModel(string $model, bool $immediately): void
    {
        $current = $this->trainingModelOn();

        if ($immediately) {
            $this->training_model           = $model;
            $this->training_model_next      = null;
            $this->training_model_next_from = null;
            return;
        }

        // Ein fälliger Wechsel wird jetzt festgeschrieben, damit das alte
        // Modell nicht wieder auftaucht.
        $this->training_model           = $current;
        $this->training_model_next      = $model === $current ? null : $model;
        $this->training_model_next_from = $model === $current ? null : now()->startOfWeek()->addWeek();
    }

    /**
     * Wie viele Notizen der Coach behält.
     *
     * Die Liste wuchs bisher unbegrenzt: jede Antwort auf eine Rückfrage und
     * jede im Chat gemerkte Kleinigkeit blieb für immer stehen. Solange das
     * nur der Chat las, war es Ballast. Seit auch die Planerstellung es liest,
     * wäre es schädlich — ein Knieproblem vom März würde noch im September
     * jede Intervalleinheit dämpfen. Die neuesten Notizen stehen unten.
     */
    public const MAX_COACH_NOTES = 25;

    /**
     * Eine Notiz anhängen und die Liste auf die jüngsten Einträge kürzen.
     */
    public function rememberNote(string $note): void
    {
        $note = trim($note);
        if ($note === '') {
            return;
        }

        $lines   = preg_split('/\R/', (string) $this->coach_notes, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines[] = '- [' . now()->format('d.m.Y') . '] ' . $note;

        $this->update([
            'coach_notes' => implode("\n", array_slice($lines, -self::MAX_COACH_NOTES)),
        ]);
    }
}
