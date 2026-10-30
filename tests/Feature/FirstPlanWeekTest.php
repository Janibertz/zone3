<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Event;
use App\Models\User;
use App\Services\LongRunPlanService;
use App\Services\PlanContextBuilder;
use App\Services\Run\RunWorkouts;
use App\Services\WeeklyPatternService;
use App\Services\WeeklyVolumeService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jans erste Planwoche, gemeldet am 29.10.2026.
 *
 * Halbmarathon am 11.04.2027, Ziel 1:25, Planstart 30.11. nach einer
 * OP-Pause. Do gesperrt, Di Laufclub. Der Plan zeigte Mo bis Mi Ruhetag,
 * einen lockeren Lauf, und am Sonntag 20 km mit 6 km in 4:01 — für Jan das
 * Tempo einer 5-km-Bestzeit. Fünf Ursachen, alle im Gerüst:
 *
 *   · der Berlin-Marathon zählte als „längster Lauf", die Leiter begann
 *     deshalb auf ihrer Spitze
 *   · die Leiter kannte den Wochenumfang nicht (20 von 36 km)
 *   · Renntempo in jeder Phase, und immer das Zieltempo
 *   · der Ruhetag nach der ersten Stufe des Wiedereinstiegs überschrieb
 *     den Laufclub, das Budget strich danach die Stufen selbst
 *   · was übrig blieb, klebte an anderen Läufen statt sich zu verteilen
 */
class FirstPlanWeekTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-10-30';
    private const START = '2026-11-30';
    private const RACE  = '2027-04-11';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::TODAY));
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::TODAY));
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
            'name'                => 'Halbmarathon Hannover',
            'event_date'          => self::RACE,
            'race_distance'       => 'half_marathon',
            'priority'            => 'A',
            'target_time_hours'   => 1,
            'target_time_minutes' => 25,
            'plan_start_date'     => self::START,
        ]);
    }

    /** Jans Wochenraster aus dem Profil. */
    private function grid(): array
    {
        return [
            'monday'    => ['available' => true,  'duration_min' => 60],
            'tuesday'   => ['available' => true,  'duration_min' => 60, 'fixed' => ['type' => 'easy_run', 'label' => 'KRAFT Laufclub']],
            'wednesday' => ['available' => true,  'duration_min' => 30],
            'thursday'  => ['available' => false, 'duration_min' => 0],
            'friday'    => ['available' => true,  'duration_min' => 120],
            'saturday'  => ['available' => true,  'duration_min' => 120],
            'sunday'    => ['available' => true,  'duration_min' => 180],
        ];
    }

    /** Die Zahlen aus Jans Prompt: 35,9 km Deckel, längster Trainingslauf 21,3 km. */
    private function volume(): array
    {
        return ['has_data' => true, 'next_week_max' => 35.9, 'longest_run' => 21.3];
    }

    private function skeleton(?array $comeback): array
    {
        $event  = $this->event();
        $from   = CarbonImmutable::parse(self::START);
        $ladder = app(LongRunPlanService::class)->forEvent($event, $this->volume(), 335, 180, $from);

        return app(WeeklyPatternService::class)->build(
            $event, $from, $from->addDays(13), $this->grid(), [], [], $comeback, $ladder, $this->volume(), 335,
        );
    }

    private function runs(array $day): array
    {
        return array_values(array_filter(
            $day['slots'],
            fn ($s) => in_array($s['type'], WeeklyPatternService::RUN_SLOT_TYPES, true),
        ));
    }

    // ── Umfang und Leiter ────────────────────────────────────────────────

    public function test_a_race_does_not_count_as_the_longest_run(): void
    {
        $user = User::factory()->create();
        Event::create([
            'user_id' => $user->id, 'name' => 'Berlin', 'event_date' => '2026-09-27',
            'race_distance' => 'marathon', 'priority' => 'A',
        ]);

        foreach ([['2026-09-27 09:00', 42.5], ['2026-10-11 09:00', 21.3], ['2026-10-09 18:00', 10.0]] as $i => [$date, $km]) {
            Activity::create([
                'user_id' => $user->id, 'strava_id' => 9000 + $i, 'name' => 'Lauf', 'type' => 'Run',
                'distance' => $km * 1000, 'moving_time' => 3600, 'elapsed_time' => 3600,
                'average_speed' => 3.0, 'start_date' => Carbon::parse($date),
            ]);
        }

        $volume = app(WeeklyVolumeService::class)->forUser($user->id, CarbonImmutable::parse(self::TODAY));

        $this->assertSame(21.3, $volume['longest_run']);
        // In den Umfang zählt das Rennen weiter — gelaufen ist es.
        $this->assertSame(42.5, collect($volume['weeks'])->max('km'));
    }

    public function test_the_long_run_is_capped_by_the_weekly_volume(): void
    {
        $ladder = app(LongRunPlanService::class)->forEvent(
            $this->event(), $this->volume(), 335, 180, CarbonImmutable::parse(self::START),
        );

        $first = reset($ladder['weeks']);

        $this->assertLessThanOrEqual(round(35.9 * LongRunPlanService::MAX_SHARE_OF_WEEK, 1), $first['km']);
        // Die Spitze kommt trotzdem — nur nicht in Woche 1.
        $this->assertSame(20.0, $ladder['peak_km']);
    }

    public function test_no_race_pace_in_the_base_phase(): void
    {
        $ladder = app(LongRunPlanService::class)->forEvent(
            $this->event(), $this->volume() + ['longest_run' => 21.3], 335, 180, CarbonImmutable::parse(self::START),
        );

        // Dezember liegt beim Halbmarathon im April in der Grundlage.
        $this->assertSame(0.0, $ladder['weeks']['2026-W49']['mp_km']);
        $this->assertSame(0.0, $ladder['weeks']['2026-W50']['mp_km']);

        // An der Spitze ist es wieder da.
        $peak = collect($ladder['weeks'])->firstWhere('kind', 'peak');
        $this->assertGreaterThan(0, $peak['mp_km']);
    }

    // ── Die Woche ────────────────────────────────────────────────────────

    /** Wiedereinstieg: der Laufclub nimmt die erste Stufe auf, statt einem Ruhetag zu weichen. */
    public function test_the_running_club_survives_the_comeback(): void
    {
        $days = $this->skeleton(['step' => 1, 'total_steps' => 5, 'trigger_label' => 'Verletzung'])['days'];

        $tuesday = $this->runs($days['2026-12-01']);
        $this->assertCount(1, $tuesday);
        $this->assertTrue($tuesday[0]['fixed']);
        $this->assertSame(1, $tuesday[0]['comeback']);

        // Die Stufen werden nicht gestrichen: vier Läufe in Woche 1.
        $week = array_filter($days, fn ($d) => $d['date'] <= '2026-12-06');
        $this->assertCount(4, array_merge(...array_values(array_map(fn ($d) => $this->runs($d), $week))));
    }

    /** Ohne Wiedereinstieg: drei Läufe plus Laufclub, und der lange Lauf frisst nicht die Woche. */
    public function test_the_first_week_has_substance(): void
    {
        $days = $this->skeleton(null)['days'];
        $week = array_filter($days, fn ($d) => $d['date'] <= '2026-12-06');
        $runs = array_merge(...array_values(array_map(fn ($d) => $this->runs($d), $week)));

        $this->assertGreaterThanOrEqual(4, count($runs));

        $long = collect($runs)->firstWhere('type', 'long_run');
        $this->assertLessThanOrEqual(35.9 * LongRunPlanService::MAX_SHARE_OF_WEEK + 0.1, $long['target_km']);
    }

    /** Nie drei Tage am Stück ohne Lauf, wenn ein freier Tag dazwischen liegt. */
    public function test_runs_are_spread_over_the_week(): void
    {
        foreach ([null, ['step' => 1, 'total_steps' => 5, 'trigger_label' => 'Verletzung']] as $comeback) {
            $gap = 0;
            foreach ($this->skeleton($comeback)['days'] as $date => $day) {
                $gap = $this->runs($day) ? 0 : $gap + 1;
                $this->assertLessThanOrEqual(2, $gap, "Drei Tage ohne Lauf bis {$date}");
            }
        }
    }

    // ── Renntempo ────────────────────────────────────────────────────────

    /** Das Renntempo wandert von der Form zum Ziel, statt ab Tag 1 das Ziel zu sein. */
    public function test_race_pace_ramps_from_form_to_goal(): void
    {
        $event    = $this->event();
        $skeleton = ['days' => [
            '2026-12-06' => ['slots' => [['type' => 'long_run']]],
            '2027-03-28' => ['slots' => [['type' => 'long_run']]],
        ]];
        $paces = ['target_sec' => 241.4, 'predicted_sec' => 265.6];

        $ramp = new \ReflectionMethod(PlanContextBuilder::class, 'rampRacePace');
        $ramp->invokeArgs(app(PlanContextBuilder::class), [&$skeleton, $event, $paces]);

        // Dezember: die heutige Form (4:25), nicht 4:01.
        $this->assertSame('4:25', $skeleton['days']['2026-12-06']['slots'][0]['race_pace']);
        // Woche des längsten Laufs: das Ziel.
        $this->assertSame('4:01', $skeleton['days']['2027-03-28']['slots'][0]['race_pace']);
    }

    public function test_the_catalog_uses_the_ramped_race_pace(): void
    {
        $built = app(RunWorkouts::class)->forSlot(
            ['type' => 'long_run', 'date' => '2027-02-07', 'workout' => 'lang_renntempo', 'distance_km' => 16, 'duration_min' => 90],
            ['type' => 'long_run', 'race_km' => 3.0, 'target_km' => 16, 'phase' => 'build', 'race_pace' => '4:12'],
            ['long' => '5:13–5:58', 'target_pace' => '4:01', 'marathon' => '4:36', 'easy' => '4:59–5:58'],
        );

        $race = collect($built['steps'])->firstWhere('label', 'Zielrenntempo');
        $this->assertStringContainsString('4:12', (string) ($race['pace_target'] ?? $race['pace'] ?? json_encode($race)));
    }
}
