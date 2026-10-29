<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Event;
use App\Models\User;
use App\Services\IntensityDistributionService;
use App\Services\LongRunPlanService;
use App\Services\PeriodizationService;
use App\Services\TrainingPlanValidator;
use App\Services\Vdot;
use App\Services\WeeklyPatternService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Periodisierung und 80/20 nach Zeit.
 *
 * Der Halbmarathon am 29.11.2026 (Sonntag) gibt das Raster vor: Taper 14
 * Tage, also liegt der längste Lauf in der Woche ab 09.11. Von dort
 * rückwärts im 3:1-Rhythmus: 19.10. und 21.09. sind Entlastungswochen,
 * 26.10. ist Belastung. 17.08. liegt mehr als 98 Tage vor dem Rennen und
 * ist Grundlage, 31.08. ist Aufbau.
 */
class PeriodizationTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY    = '2026-08-31'; // ein Montag
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
            'user_id'             => ($user ?? User::factory()->create())->id,
            'name'                => 'Halbmarathon',
            'event_date'          => self::RACE_DAY,
            'race_distance'       => 'half_marathon',
            'priority'            => 'A',
            'target_time_hours'   => 1,
            'target_time_minutes' => 29,
        ]);
    }

    private function availability(int $minutes = 90): array
    {
        return collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
            ->mapWithKeys(fn ($d) => [$d => ['available' => true, 'duration_min' => $minutes]])
            ->all();
    }

    private function week(Event $event, string $monday, float $weeklyMax = 50, ?array $longRuns = null, int $minutes = 90): array
    {
        $from = CarbonImmutable::parse($monday);

        return app(WeeklyPatternService::class)->build(
            $event, $from, $from->addDays(6), $this->availability($minutes),
            [], [], null, $longRuns,
            ['has_data' => true, 'next_week_max' => $weeklyMax],
            330,
        );
    }

    private function runSlots(array $skeleton): array
    {
        return collect($skeleton['days'])
            ->flatMap(fn ($d) => $d['slots'] ?? [])
            ->filter(fn ($s) => in_array($s['type'], WeeklyPatternService::RUN_SLOT_TYPES, true))
            ->values()
            ->all();
    }

    // ── Phasen und Entlastung ────────────────────────────────────────────

    public function test_recovery_falls_every_fourth_week_counted_back_from_the_peak(): void
    {
        $event   = $this->event();
        $periods = app(PeriodizationService::class);

        $this->assertFalse($periods->forWeek($event, CarbonImmutable::parse('2026-11-09'))['recovery'], 'Peak-Woche ist Belastung');
        $this->assertFalse($periods->forWeek($event, CarbonImmutable::parse('2026-10-26'))['recovery']);
        $this->assertTrue($periods->forWeek($event, CarbonImmutable::parse('2026-10-19'))['recovery']);
        $this->assertTrue($periods->forWeek($event, CarbonImmutable::parse('2026-09-21'))['recovery']);

        $this->assertSame('base',  $periods->forWeek($event, CarbonImmutable::parse('2026-08-17'))['phase']);
        $this->assertSame('build', $periods->forWeek($event, CarbonImmutable::parse('2026-08-31'))['phase']);
        $this->assertSame('peak',  $periods->forWeek($event, CarbonImmutable::parse('2026-11-09'))['phase']);
    }

    public function test_taper_and_race_week_reduce_volume(): void
    {
        $event   = $this->event();
        $periods = app(PeriodizationService::class);

        $taper = $periods->forWeek($event, CarbonImmutable::parse('2026-11-16'));
        $race  = $periods->forWeek($event, CarbonImmutable::parse('2026-11-23'));

        $this->assertSame('taper', $taper['phase']);
        $this->assertSame(0.7, $taper['volume_factor']);
        $this->assertSame('race_week', $race['phase']);
        $this->assertSame(0.5, $race['volume_factor']);
        $this->assertSame(1, $race['max_hard']);
    }

    /**
     * Der eigentliche Grund für die Klasse: die Leiter zählte ihre
     * Entlastung ab heute. Bei einem Plan, der jeden Sonntag neu ansetzt,
     * rutschte die Entlastungswoche jedes Mal eine Woche weiter.
     */
    public function test_the_recovery_week_does_not_move_when_the_plan_is_rebuilt(): void
    {
        $event  = $this->event();
        $ladder = app(LongRunPlanService::class);
        $volume = ['longest_run' => 12.0];

        $first  = $ladder->forEvent($event, $volume, 330, 150, CarbonImmutable::parse('2026-09-07'));
        $second = $ladder->forEvent($event, $volume, 330, 150, CarbonImmutable::parse('2026-09-14'));

        foreach (['2026-W39', '2026-W43'] as $week) {
            $this->assertSame('cutback', $first['weeks'][$week]['kind'], "{$week} im ersten Plan");
            $this->assertSame('cutback', $second['weeks'][$week]['kind'], "{$week} eine Woche später");
        }
    }

    // ── Das Gerüst setzt die Phase um ────────────────────────────────────

    public function test_the_base_phase_plans_no_intervals(): void
    {
        $skeleton = $this->week($this->event(), '2026-08-17');
        $types    = array_column($this->runSlots($skeleton), 'type');

        $this->assertNotContains('interval', $types);
        $this->assertLessThanOrEqual(1, count(array_intersect($types, WeeklyPatternService::HARD_TYPES)));
    }

    public function test_a_recovery_week_has_less_volume_and_one_hard_session(): void
    {
        $event    = $this->event();
        $recovery = $this->week($event, '2026-10-19');
        $load     = $this->week($event, '2026-10-26');

        $km = fn ($s) => array_sum(array_map(fn ($slot) => (float) ($slot['target_km'] ?? 0), $this->runSlots($s)));
        $hard = fn ($s) => count(array_filter($this->runSlots($s), fn ($slot) => in_array($slot['type'], WeeklyPatternService::HARD_TYPES, true)));

        $this->assertLessThan($km($load) * 0.85, $km($recovery));
        $this->assertSame(1, $hard($recovery));
        $this->assertSame(2, $hard($load));
    }

    // ── 80/20 nach Zeit ──────────────────────────────────────────────────

    public function test_hard_minutes_stay_within_twenty_percent(): void
    {
        $slots = $this->runSlots($this->week($this->event(), '2026-10-26'));

        $total = array_sum(array_column($slots, 'target_min'));
        $hard  = array_sum(array_map(fn ($s) => $s['hard_min'] ?? 0, $slots));

        $this->assertGreaterThan(0, $hard, 'Die harten Einheiten tragen einen Hauptteil');
        $this->assertLessThanOrEqual($total * WeeklyPatternService::HARD_SHARE + 1, $hard);
    }

    /**
     * Ein langer Lauf mit viel Renntempo verbraucht den Anteil. Für zwei
     * harte Hauptteile reicht der Rest nicht — die unwichtigere Einheit wird
     * locker, statt zwei halbe Reize zu setzen.
     */
    public function test_race_pace_in_the_long_run_turns_a_hard_session_easy(): void
    {
        $longRuns = ['weeks' => ['2026-W44' => ['km' => 20.0, 'min' => 110, 'kind' => 'build', 'mp_km' => 8.0]]];
        $slots    = $this->runSlots($this->week($this->event(), '2026-10-26', 55, $longRuns, 120));

        $types = array_column($slots, 'type');
        $this->assertNotContains('interval', $types, 'Beim Halbmarathon fällt das Intervall vor dem Tempolauf');
        $this->assertContains('interval', array_column($slots, 'eighty_twenty'));

        $tempo = collect($slots)->firstWhere('type', 'tempo_run');
        $this->assertGreaterThanOrEqual(WeeklyPatternService::MIN_HARD_MAIN_MINUTES, $tempo['hard_min']);
    }

    public function test_the_prompt_names_the_main_set_and_the_weekly_share(): void
    {
        $text = app(WeeklyPatternService::class)->toPromptSection($this->week($this->event(), '2026-10-26'));

        $this->assertStringContainsString('Hauptteil (Zone 4–5) hoechstens', $text);
        $this->assertStringContainsString('min hart', $text);
        $this->assertStringContainsString('hard_min', $text);
    }

    // ── Der Validator ────────────────────────────────────────────────────

    /** Eine Modellantwort, die dem Gerüst genau folgt. */
    private function answer(array $skeleton, callable $tweak = null): array
    {
        $sessions = [];
        foreach ($skeleton['days'] as $date => $day) {
            if (! $day['slots']) {
                $sessions[] = ['date' => $date, 'type' => 'rest', 'title' => 'Ruhetag', 'duration_min' => 0, 'distance_km' => 0];
                continue;
            }

            foreach ($day['slots'] as $slot) {
                $s = [
                    'date'         => $date,
                    'type'         => $slot['type'],
                    'title'        => $slot['type'],
                    'description'  => 'Hauptteil 3×10 min.',
                    'duration_min' => $slot['target_min'] ?? 45,
                    'distance_km'  => $slot['target_km'] ?? 8,
                    'hard_min'     => $slot['hard_min'] ?? 0,
                ];
                $sessions[] = $tweak ? $tweak($s, $slot) : $s;
            }
        }

        return $sessions;
    }

    public function test_a_main_set_above_the_skeleton_is_cut_back(): void
    {
        $skeleton = $this->week($this->event(), '2026-10-26');
        $result   = app(TrainingPlanValidator::class)->validate(
            $this->answer($skeleton, fn ($s, $slot) => $s['type'] === 'tempo_run' ? [...$s, 'hard_min' => $slot['hard_min'] + 15] : $s),
            $skeleton,
        );

        $tempo = collect($result['sessions'])->firstWhere('type', 'tempo_run');
        $slot  = collect($this->runSlots($skeleton))->firstWhere('type', 'tempo_run');

        $this->assertSame($slot['hard_min'], $tempo['hard_min']);
        // Der gekuerzte Hauptteil steht im Ablauf, nicht als Satz im Text.
        $this->assertFalse(\App\Services\Run\SessionText::hasStructure($tempo['description']));
        $this->assertTrue(collect($result['report'])->contains(fn ($r) => str_contains($r, '80/20')));
    }

    public function test_an_answer_that_follows_the_skeleton_is_left_alone(): void
    {
        $skeleton = $this->week($this->event(), '2026-10-26');
        $result   = app(TrainingPlanValidator::class)->validate(
            // Ohne hard_min: das Modell vergisst die Zahl oft. Dann gilt das
            // Gerüst, und es wird nichts „korrigiert".
            $this->answer($skeleton, fn ($s) => array_diff_key($s, ['hard_min' => 1])),
            $skeleton,
        );

        $this->assertFalse(collect($result['report'])->contains(fn ($r) => str_contains($r, '80/20')));
    }

    /**
     * Die Woche zählt, nicht die Einheit. Ohne Laufdaten hat das Gerüst
     * keine Zielminuten — dann prüft erst der Validator, was das Modell
     * tatsächlich plant. Kurze lockere Läufe neben zwei vollen harten
     * Einheiten liegen über 20 %, obwohl jeder Hauptteil für sich passt.
     */
    public function test_a_week_over_twenty_percent_gets_shorter_main_sets(): void
    {
        $from     = CarbonImmutable::parse('2026-10-26');
        $skeleton = app(WeeklyPatternService::class)->build(
            $this->event(), $from, $from->addDays(6), $this->availability(),
        );

        $result = app(TrainingPlanValidator::class)->validate(
            $this->answer($skeleton, fn ($s, $slot) => [...$s, 'duration_min' => match ($s['type']) {
                'easy_run' => 20,
                'long_run' => 40,
                'tempo_run', 'interval' => 50,
                default => min(20, (int) $slot['max_min']),
            }]),
            $skeleton,
        );

        $runs  = collect($result['sessions'])->filter(fn ($s) => in_array($s['type'], TrainingPlanValidator::RUN_TYPES, true));
        $total = $runs->sum('duration_min');
        $hard  = $runs->sum(fn ($s) => $s['hard_min'] ?? 0);

        $this->assertTrue(collect($result['report'])->contains(fn ($r) => str_contains($r, 'Hauptteile gekürzt')), implode("\n", $result['report']));
        $this->assertLessThanOrEqual($total * WeeklyPatternService::HARD_SHARE + 2, $hard);
    }

    // ── Was tatsächlich gelaufen wurde ───────────────────────────────────

    private function addRun(User $user, int $daysAgo, array $lapPaces): void
    {
        Activity::create([
            'user_id'      => $user->id,
            'strava_id'    => $this->seq++,
            'name'         => 'Lauf',
            'type'         => 'Run',
            'distance'     => count($lapPaces) * 1000,
            'moving_time'  => array_sum($lapPaces),
            'elapsed_time' => array_sum($lapPaces),
            'start_date'   => now()->subDays($daysAgo),
            'laps'         => array_map(fn ($p) => ['moving_time' => $p, 'distance' => 1000], $lapPaces),
        ]);
    }

    public function test_the_distribution_counts_laps_by_pace(): void
    {
        $user = User::factory()->create();
        $vdot = Vdot::fromThresholdPace(270); // T = 4:30

        // Acht lockere Kilometer, zwei an der Schwelle.
        $this->addRun($user, 2, [360, 360, 360, 360, 360, 360, 360, 360, 270, 270]);

        $d = app(IntensityDistributionService::class)->forUser($user->id, $vdot);

        $this->assertSame(48, $d['easy_min']);
        $this->assertSame(9, $d['hard_min']);
        $this->assertSame(16, $d['hard_pct']);
        $this->assertStringContainsString('passt zu 80/20', app(IntensityDistributionService::class)->toPromptSection($d));
    }

    public function test_easy_runs_that_are_too_fast_are_named(): void
    {
        $user = User::factory()->create();
        $vdot = Vdot::fromThresholdPace(270);

        // „Locker" in 4:50 — zu schnell für Zone 2 bei T = 4:30.
        $this->addRun($user, 3, [290, 290, 290, 290, 290, 290]);
        $this->addRun($user, 5, [360, 360, 360, 360]);

        $d = app(IntensityDistributionService::class)->forUser($user->id, $vdot);

        $this->assertGreaterThan(IntensityDistributionService::TOO_HARD_PCT, $d['hard_pct']);
        $this->assertStringContainsString('lockeren Läufe zu schnell', app(IntensityDistributionService::class)->toPromptSection($d));
    }

    /**
     * Der Phasenbalken der Planseite: von heute (Aufbau, 90 Tage vorher)
     * bis zum Rennen, ohne die schon vergangene Grundlage, und mit
     * denselben Grenzen wie phaseOn().
     */
    public function test_segments_cover_today_to_race_with_the_same_boundaries(): void
    {
        $event    = $this->event();
        $service  = app(PeriodizationService::class);
        $segments = $service->segments($event);

        $this->assertSame(['build', 'peak', 'taper', 'race_week'], array_column($segments, 'key'));
        $this->assertSame($service->phaseOn($event)['key'], $segments[0]['key']);
        $this->assertSame(90 + 1, array_sum(array_column($segments, 'days')));
        $this->assertSame(PeriodizationService::PEAK_PHASE_DAYS, $segments[1]['days']);
    }

    public function test_segments_are_empty_after_the_race(): void
    {
        Carbon::setTestNow('2026-12-01');
        CarbonImmutable::setTestNow('2026-12-01');

        $this->assertSame([], app(PeriodizationService::class)->segments($this->event()));
    }
}
