<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Event;
use App\Models\User;
use App\Services\PeriodizationService;
use App\Services\WeeklyPatternService;
use App\Services\WeeklyVolumeService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Umfang wie in Jans Coach-Plan bei Mats (Entscheidung vom 30.10.2026):
 *
 *   · der Start richtet sich nach dem Niveau vor einer Pause, nicht nach
 *     den leeren Wochen danach
 *   · gesteigert wird um 8 % je Belastungswoche
 *   · die Zahl der Läufe kommt aus der Verfügbarkeit, mindestens vier —
 *     ist der Umfang knapp, werden Läufe kürzer statt gestrichen
 *   · in der Entlastung wird das Laufen etwa halbiert
 */
class CoachLikeVolumeTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY    = '2026-08-31';
    private const RACE_DAY = '2026-11-29';

    private int $seq = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY);
        CarbonImmutable::setTestNow(self::TODAY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function event(?User $user = null): Event
    {
        return Event::create([
            'user_id' => ($user ?? User::factory()->create())->id, 'name' => 'Halbmarathon',
            'event_date' => self::RACE_DAY, 'race_distance' => 'half_marathon', 'priority' => 'A',
            'target_time_hours' => 1, 'target_time_minutes' => 29,
        ]);
    }

    private function addRun(User $user, string $date, float $km): void
    {
        Activity::create([
            'user_id' => $user->id, 'strava_id' => $this->seq++, 'name' => 'Lauf', 'type' => 'Run',
            'distance' => $km * 1000, 'moving_time' => (int) ($km * 330), 'elapsed_time' => (int) ($km * 330),
            'start_date' => "{$date} 07:00:00",
        ]);
    }

    private function week(string $monday, array $volume, int $minutes = 90, int $days = 7): array
    {
        $from  = CarbonImmutable::parse($monday);
        $avail = collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
            ->mapWithKeys(fn ($d, $i) => [$d => ['available' => $i < $days, 'duration_min' => $i < $days ? $minutes : 0]])
            ->all();

        return app(WeeklyPatternService::class)->build(
            $this->event(), $from, $from->addDays(6), $avail, [], [], null, null, $volume, 330,
        );
    }

    private function runs(array $skeleton): \Illuminate\Support\Collection
    {
        return collect($skeleton['days'])
            ->flatMap(fn ($d, $date) => collect($d['slots'] ?? [])->map(fn ($s) => $s + ['date' => $date]))
            ->filter(fn ($s) => in_array($s['type'], WeeklyPatternService::RUN_SLOT_TYPES, true))
            ->values();
    }

    // ── Startumfang ──────────────────────────────────────────────────────

    /** Nach einer Pause startet der Deckel beim Niveau davor, nicht bei null. */
    public function test_the_start_follows_the_level_before_a_break(): void
    {
        $user = User::factory()->create();

        // Vier Wochen à 50 km bis Mitte Juli, danach nur noch zwei kurze Läufe.
        foreach (['2026-06-22', '2026-06-29', '2026-07-06', '2026-07-13'] as $monday) {
            foreach ([0, 2, 4, 6] as $d) {
                $this->addRun($user, CarbonImmutable::parse($monday)->addDays($d)->toDateString(), 12.5);
            }
        }
        $this->addRun($user, '2026-08-25', 5);
        $this->addRun($user, '2026-08-27', 5);

        $v = app(WeeklyVolumeService::class)->forUser($user->id);

        $this->assertSame(50.0, $v['level_km']);
        $this->assertSame(round(50 * WeeklyVolumeService::LEVEL_START_SHARE, 1), $v['next_week_max']);
    }

    /** Wer durchgehend läuft, steigert vom Schnitt aus — das Niveau ist dort nur eine Untergrenze. */
    public function test_a_steady_runner_still_progresses_from_the_average(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 6) as $w) {
            $monday = CarbonImmutable::parse(self::TODAY)->startOfWeek()->subWeeks($w);
            foreach ([0, 2, 4] as $d) {
                $this->addRun($user, $monday->addDays($d)->toDateString(), 10);
            }
        }

        $v = app(WeeklyVolumeService::class)->forUser($user->id);

        $this->assertSame(32.4, $v['next_week_max'], '30 km + 8 %');
    }

    // ── Läufe aus der Verfügbarkeit ──────────────────────────────────────

    /** Ein knapper Deckel macht die lockeren Läufe kürzer, statt sie zu streichen. */
    public function test_a_tight_budget_shortens_instead_of_dropping(): void
    {
        $sk   = $this->week('2026-08-31', ['has_data' => true, 'next_week_max' => 25.0], days: 5);
        $runs = $this->runs($sk);

        $this->assertGreaterThanOrEqual(WeeklyPatternService::MIN_RUNS_PER_WEEK, $runs->count());
        foreach ($runs->where('type', 'easy_run') as $run) {
            $this->assertGreaterThanOrEqual(WeeklyPatternService::MIN_USEFUL_RUN_MINUTES, $run['target_min']);
        }
    }

    /** Unter vier Läufe streicht das Budget nie, auch wenn die Mindestdauern nicht passen. */
    public function test_never_fewer_than_four_runs(): void
    {
        $runs = $this->runs($this->week('2026-08-31', ['has_data' => true, 'next_week_max' => 10.0]));

        $this->assertGreaterThanOrEqual(WeeklyPatternService::MIN_RUNS_PER_WEEK, $runs->count());
    }

    /** Fallen dabei Läufe, bleibt 80/20 nach Einheiten: unter fünf Läufen eine harte. */
    public function test_a_short_week_keeps_one_hard_session(): void
    {
        $runs = $this->runs($this->week('2026-08-31', ['has_data' => true, 'next_week_max' => 10.0]));

        if ($runs->count() < 5) {
            $this->assertLessThanOrEqual(1, $runs->whereIn('type', WeeklyPatternService::HARD_TYPES)->count());
        }
    }

    // ── Entlastung ───────────────────────────────────────────────────────

    public function test_a_recovery_week_halves_the_running(): void
    {
        $this->assertSame(0.5, PeriodizationService::RECOVERY_FACTOR);

        // Gleicher Deckel, einmal Belastung (12.10.), einmal Entlastung (19.10.).
        $volume = ['has_data' => true, 'next_week_max' => 60.0];
        $load   = $this->runs($this->week('2026-10-12', $volume))->sum('target_km');
        $rest   = $this->runs($this->week('2026-10-19', $volume))->sum('target_km');

        $this->assertTrue(app(PeriodizationService::class)->forWeek($this->event(), CarbonImmutable::parse('2026-10-19'))['recovery']);
        $this->assertLessThan($load * 0.65, $rest);
    }

    /** Nach der Entlastung geht es weiter, wo die Belastung aufgehört hat — kein doppelter Sprung. */
    public function test_progression_counts_only_load_weeks(): void
    {
        $from  = CarbonImmutable::parse('2026-10-12');
        $avail = collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
            ->mapWithKeys(fn ($d) => [$d => ['available' => true, 'duration_min' => 300]])->all();

        $sk = app(WeeklyPatternService::class)->build(
            $this->event(), $from, $from->addDays(20), $avail, [], [], null, null,
            ['has_data' => true, 'next_week_max' => 40.0], 330,
        );

        $km = $this->runs($sk)->groupBy(fn ($s) => CarbonImmutable::parse($s['date'])->startOfWeek()->toDateString())
            ->map(fn ($w) => $w->sum('target_km'));

        // 12.10. Belastung, 19.10. Entlastung, 26.10. Belastung: die dritte
        // Woche liegt 8 % über der ersten, nicht 16 %.
        $this->assertEqualsWithDelta($km['2026-10-12'] * 1.08, $km['2026-10-26'], $km['2026-10-12'] * 0.03);
    }
}
