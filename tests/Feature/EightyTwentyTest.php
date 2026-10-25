<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Services\TrainingPaceService;
use App\Services\WeeklyPatternService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Drei Stellen, an denen das Gerüst der Trainingslehre widersprach, die im
 * Prompt stand.
 *
 * - 80/20: in einer knappen Woche flog beim Halbmarathon zuerst der lockere
 *   Lauf raus — übrig blieben langer Lauf, Tempolauf und Intervalle.
 * - Die Intervall-Pace reichte bis vier Sekunden langsamer als die Schwelle.
 * - Die Phase sagte 14 Tage vor einem Halbmarathon „Rennwoche, kein Umfang",
 *   während die Leiter dort den längsten Lauf setzte.
 */
class EightyTwentyTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-08-17'; // ein Montag

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function event(string $distance = 'half_marathon', int $daysUntil = 60): Event
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        return Event::create([
            'user_id'             => $user->id,
            'name'                => 'Testrennen',
            'event_date'          => now()->addDays($daysUntil),
            'race_distance'       => $distance,
            'priority'            => 'A',
            'target_time_hours'   => 1,
            'target_time_minutes' => 30,
        ]);
    }

    /** Nur die genannten Tage verfügbar, je 90 Minuten. */
    private function availability(array $days): array
    {
        $out = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $out[$day] = in_array($day, $days, true)
                ? ['available' => true,  'duration_min' => 90]
                : ['available' => false, 'duration_min' => 0];
        }

        return $out;
    }

    private function build(Event $event, array $availability, ?array $volume = null): array
    {
        $from = CarbonImmutable::parse(self::TODAY);

        return app(WeeklyPatternService::class)->build(
            $event, $from, $from->addDays(13), $availability,
            [], [], null, null, $volume, $volume ? 330 : null,
        );
    }

    private function runTypes(array $skeleton, array $dates): array
    {
        return collect($dates)
            ->flatMap(fn ($d) => $skeleton['days'][$d]['slots'] ?? [])
            ->pluck('type')
            ->filter(fn ($t) => in_array($t, WeeklyPatternService::RUN_SLOT_TYPES, true))
            ->values()
            ->all();
    }

    // ── 80/20 im Gerüst ──────────────────────────────────────────────────

    /** Vier Lauftage: langer Lauf, eine harte Einheit, ein lockerer Lauf — nicht zwei harte. */
    public function test_a_four_day_week_keeps_an_easy_run_and_one_hard_session(): void
    {
        $skeleton = $this->build(
            $this->event(),
            $this->availability(['tuesday', 'thursday', 'saturday', 'sunday']),
        );

        foreach ($skeleton['weeks'] as $week => $data) {
            $this->assertContains('easy_run', $data['planned'], "Woche {$week} ohne lockeren Lauf");

            $hard = array_intersect($data['planned'], WeeklyPatternService::HARD_TYPES);
            $this->assertLessThanOrEqual(1, count($hard), "Woche {$week} hat bei vier Lauftagen zwei harte Einheiten");
        }
    }

    /** Ab fünf Einheiten verträgt die Woche wieder zwei harte. */
    public function test_a_full_week_still_gets_two_hard_sessions(): void
    {
        $skeleton = $this->build(
            $this->event(),
            $this->availability(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']),
        );

        foreach ($skeleton['weeks'] as $week => $data) {
            $this->assertContains('tempo_run', $data['planned'], "Woche {$week} ohne Tempolauf");
            $this->assertContains('interval',  $data['planned'], "Woche {$week} ohne Intervall");
        }
    }

    /**
     * Ein knapper Umfangsdeckel streicht zuerst eine harte Einheit, nie den
     * letzten lockeren Lauf.
     */
    public function test_a_tight_budget_drops_a_hard_session_before_the_last_easy_run(): void
    {
        $skeleton = $this->build(
            $this->event(),
            $this->availability(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']),
            ['has_data' => true, 'next_week_max' => 25.0],
        );

        $firstWeek = array_slice(array_keys($skeleton['days']), 0, 7);
        $types     = $this->runTypes($skeleton, $firstWeek);

        if (array_intersect($types, WeeklyPatternService::HARD_TYPES) !== []) {
            $this->assertContains('easy_run', $types, 'Harte Einheit überlebt, lockerer Lauf nicht: ' . implode(', ', $types));
        }

        $this->assertLessThanOrEqual(
            1,
            count(array_intersect($types, WeeklyPatternService::HARD_TYPES)),
            'Bei 25 km bleibt höchstens eine harte Einheit: ' . implode(', ', $types),
        );
    }

    // ── Paces ────────────────────────────────────────────────────────────

    /** Intervalle laufen schneller als die Schwelle — am oberen Ende auch. */
    public function test_interval_pace_is_faster_than_threshold(): void
    {
        $paces = app(TrainingPaceService::class)->forEvent($this->event(), \App\Services\Vdot::fromThresholdPace(262));

        $this->assertSame('4:22', $paces['threshold']);
        $this->assertSame('3:56–4:01', $paces['interval'], 'Daniels I: 97,5–100 % VO2max');
        $this->assertSame('3:47', $paces['repetition'], 'Daniels R: ~105 %');
    }

    // ── Phase und Taper ──────────────────────────────────────────────────

    /** Beim Halbmarathon beginnt der Taper dort, wo die Leiter den Peak setzt. */
    public function test_half_marathon_phases_match_the_long_run_ladder(): void
    {
        $this->assertSame('race_week', $this->event('half_marathon', 7)->training_phase['key']);
        $this->assertSame('taper',     $this->event('half_marathon', 10)->training_phase['key']);
        $this->assertSame('taper',     $this->event('half_marathon', 14)->training_phase['key']);
        $this->assertSame('peak',      $this->event('half_marathon', 15)->training_phase['key']);
        $this->assertSame('peak',      $this->event('half_marathon', 56)->training_phase['key']);
        $this->assertSame('build',     $this->event('half_marathon', 57)->training_phase['key']);
        $this->assertSame('base',      $this->event('half_marathon', 99)->training_phase['key']);
    }

    /** Der Marathon tapert länger, der 5er kaum. */
    public function test_taper_length_depends_on_the_distance(): void
    {
        $this->assertSame('taper',     $this->event('marathon', 21)->training_phase['key']);
        $this->assertSame('peak',      $this->event('half_marathon', 21)->training_phase['key']);
        $this->assertSame('race_week', $this->event('5km', 7)->training_phase['key']);
        $this->assertSame('peak',      $this->event('5km', 8)->training_phase['key']);
    }
}
