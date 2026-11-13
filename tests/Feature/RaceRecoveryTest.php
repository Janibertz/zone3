<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Plan\RaceRecovery;
use App\Services\WeeklyPatternService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Erholung nach dem Rennen (Idee 4, Jans Go vom 13.11.2026).
 *
 * Vorher lief der Plan am Tag nach dem Rennen weiter, als wäre nichts
 * gewesen. Jetzt legt das Gerüst die Tage danach fest: Ruhetag(e), dann
 * kurz und locker, dann locker in normaler Länge — ohne harte Einheit.
 */
class RaceRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-08-17';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::MONDAY);
        CarbonImmutable::setTestNow(self::MONDAY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function event(string $distance = 'marathon', int $daysUntil = 90, array $extra = []): Event
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        return Event::create(array_merge([
            'user_id'             => $user->id,
            'name'                => 'Zielrennen',
            'event_date'          => CarbonImmutable::parse(self::MONDAY)->addDays($daysUntil),
            'race_distance'       => $distance,
            'priority'            => 'A',
            'target_time_hours'   => 3,
            'target_time_minutes' => 30,
        ], $extra));
    }

    private function availability(int $minutes = 120): array
    {
        $days = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $days[$day] = ['available' => true, 'duration_min' => $minutes];
        }

        return $days;
    }

    private function build(Event $event, array $races, array $recovery, ?array $availability = null, ?array $model = null): array
    {
        $from = CarbonImmutable::parse(self::MONDAY);

        return app(WeeklyPatternService::class)->build(
            $event,
            $from,
            $from->addDays(13),
            $availability ?? $this->availability(),
            [], [], null, null, null, null,
            $races,
            $model,
            null,
            null,
            $recovery,
        );
    }

    private function race(string $date, string $name, float $km, string $priority = 'A'): array
    {
        return ['date' => $date, 'name' => $name, 'km' => $km, 'priority' => $priority];
    }

    private function hardOn(array $skeleton, string $date): bool
    {
        return collect($skeleton['days'][$date]['slots'])->contains(fn ($s) => ! empty($s['hard']) && empty($s['race']));
    }

    public function test_the_length_follows_the_race_distance(): void
    {
        $this->assertSame(['days' => 2, 'rest_days' => 1], RaceRecovery::lengthFor(5.0));
        $this->assertSame(['days' => 3, 'rest_days' => 1], RaceRecovery::lengthFor(10.0));
        $this->assertSame(['days' => 7, 'rest_days' => 2], RaceRecovery::lengthFor(21.0975));
        $this->assertSame(['days' => 14, 'rest_days' => 2], RaceRecovery::lengthFor(42.195));
        $this->assertSame(21, RaceRecovery::lengthFor(100.0)['days']);
    }

    public function test_a_c_race_halves_the_recovery(): void
    {
        $this->assertSame(['days' => 4, 'rest_days' => 1], RaceRecovery::lengthFor(21.0975, 'C'));
        $this->assertSame(['days' => 1, 'rest_days' => 1], RaceRecovery::lengthFor(5.0, 'C'));
    }

    public function test_a_half_marathon_is_followed_by_two_rest_days_then_short_then_easy(): void
    {
        $race = $this->race('2026-08-23', 'Stadtlauf HM', 21.0975);
        $map  = app(RaceRecovery::class)->spread([$race], CarbonImmutable::parse(self::MONDAY), CarbonImmutable::parse('2026-08-30'));

        $this->assertSame(
            ['rest', 'rest', 'short', 'short', 'easy', 'easy', 'easy'],
            array_column(array_values($map), 'state'),
        );
        $this->assertSame('2026-08-24', array_key_first($map));
        $this->assertArrayNotHasKey('2026-08-23', $map, 'Der Renntag selbst ist keine Erholung.');
    }

    public function test_the_skeleton_plans_no_hard_session_after_the_race(): void
    {
        $event    = $this->event();
        $race     = $this->race('2026-08-23', 'Stadtlauf HM', 21.0975, 'B');
        $recovery = app(RaceRecovery::class)->spread([$race], CarbonImmutable::parse(self::MONDAY), CarbonImmutable::parse('2026-08-30'));

        $skeleton = $this->build($event, ['2026-08-23' => $race + ['is_goal' => false]], $recovery);
        $days     = $skeleton['days'];

        $this->assertFalse($days['2026-08-24']['available'], 'Tag 1 ist Ruhetag');
        $this->assertFalse($days['2026-08-25']['available'], 'Tag 2 ist Ruhetag');

        foreach (['2026-08-26', '2026-08-27', '2026-08-28', '2026-08-29', '2026-08-30'] as $d) {
            $this->assertFalse($this->hardOn($skeleton, $d), "{$d} darf nicht hart sein");
        }

        foreach (['2026-08-26', '2026-08-27'] as $d) {
            foreach ($days[$d]['slots'] as $slot) {
                $this->assertNotSame('long_run', $slot['type'], "{$d}: kein langer Lauf in der kurzen Hälfte");
                if ($slot['type'] === 'easy_run') {
                    $this->assertLessThanOrEqual(RaceRecovery::SHORT_MAX_MIN, $slot['max_min']);
                    $this->assertSame('short', $slot['recovery']);
                }
            }
        }

        foreach ($days['2026-08-30']['slots'] as $slot) {
            if ($slot['type'] === 'long_run') {
                $this->assertEquals(0.0, $slot['race_km'] ?? 0.0, 'Ein langer Lauf in der Erholung ist ohne Renntempo.');
            }
        }

        // Die Woche davor bleibt unberührt: dort darf hart trainiert werden.
        $this->assertTrue(collect(['2026-08-17', '2026-08-18', '2026-08-19', '2026-08-20'])
            ->contains(fn ($d) => $this->hardOn($skeleton, $d)));
    }

    public function test_the_prompt_names_the_recovery(): void
    {
        $event    = $this->event();
        $race     = $this->race('2026-08-23', 'Stadtlauf HM', 21.0975, 'B');
        $recovery = app(RaceRecovery::class)->spread([$race], CarbonImmutable::parse(self::MONDAY), CarbonImmutable::parse('2026-08-30'));

        $prompt = app(WeeklyPatternService::class)->toPromptSection(
            $this->build($event, ['2026-08-23' => $race + ['is_goal' => false]], $recovery),
        );

        $this->assertStringContainsString('RUHETAG nach dem Rennen Stadtlauf HM', $prompt);
        $this->assertStringContainsString('ERHOLUNG nach dem Rennen Stadtlauf HM', $prompt);
    }

    public function test_a_hard_club_session_falls_away_during_recovery(): void
    {
        $event = $this->event();
        $avail = $this->availability();
        $avail['thursday']['fixed'] = ['type' => 'interval', 'label' => 'Laufclub'];

        $race     = $this->race('2026-08-23', 'Stadtlauf HM', 21.0975, 'B');
        $recovery = app(RaceRecovery::class)->spread([$race], CarbonImmutable::parse(self::MONDAY), CarbonImmutable::parse('2026-08-30'));

        $skeleton = $this->build($event, ['2026-08-23' => $race + ['is_goal' => false]], $recovery, $avail);

        $this->assertNull($skeleton['days']['2026-08-27']['fixed'], 'Der Club-Termin fällt in der Erholung aus.');
        $this->assertNotNull($skeleton['days']['2026-08-20']['fixed'], 'Eine Woche vorher steht er noch.');
    }

    public function test_the_norwegian_week_stays_easy_after_the_race(): void
    {
        $event = $this->event();
        $avail = $this->availability();
        foreach ($avail as $k => $d) {
            $avail[$k]['second_min'] = 60;
        }

        $race     = $this->race('2026-08-16', 'Stadtlauf HM', 21.0975);
        $recovery = app(RaceRecovery::class)->spread([$race], CarbonImmutable::parse(self::MONDAY), CarbonImmutable::parse('2026-08-30'));

        $skeleton = $this->build($event, [], $recovery, $avail, ['name' => 'norwegian']);

        foreach (['2026-08-19', '2026-08-20', '2026-08-21', '2026-08-22', '2026-08-23'] as $d) {
            $slots = $skeleton['days'][$d]['slots'];
            $this->assertFalse($this->hardOn($skeleton, $d), "{$d} darf nicht hart sein");
            $this->assertLessThanOrEqual(1, collect($slots)->filter(fn ($s) => in_array($s['type'], WeeklyPatternService::RUN_SLOT_TYPES, true))->count());
        }
    }

    public function test_a_race_before_the_window_still_counts(): void
    {
        $event = $this->event();
        Event::create([
            'user_id'       => $event->user_id,
            'name'          => 'Herbst-HM',
            'event_date'    => '2026-08-16',
            'race_distance' => 'half_marathon',
            'priority'      => 'A',
        ]);

        $map = app(RaceRecovery::class)->forWindow($event->user, CarbonImmutable::parse(self::MONDAY), CarbonImmutable::parse('2026-08-30'));

        $this->assertSame('rest', $map['2026-08-17']['state']);
        $this->assertSame('rest', $map['2026-08-18']['state']);
        $this->assertSame('short', $map['2026-08-19']['state']);
        $this->assertSame('easy', $map['2026-08-23']['state']);
        $this->assertArrayNotHasKey('2026-08-24', $map);
        $this->assertSame('Herbst-HM', $map['2026-08-17']['race']);
    }

    public function test_a_five_k_test_from_the_plan_counts_like_a_five_k_race(): void
    {
        $event = $this->event('5km', 90, ['goal_type' => 'open', 'open_focus' => 'speed', 'open_test' => true]);

        TrainingSession::create([
            'user_id'      => $event->user_id,
            'event_id'     => $event->id,
            'planned_date' => '2026-08-16',
            'type'         => 'race_prep',
            'title'        => '5-km-Test',
            'distance_km'  => 5,
            'status'       => 'completed',
            'intensity'    => 'high',
        ]);

        $map = app(RaceRecovery::class)->forWindow($event->user, CarbonImmutable::parse(self::MONDAY), CarbonImmutable::parse('2026-08-30'));

        $this->assertSame(['2026-08-17', '2026-08-18'], array_keys($map), '5 km: zwei Tage');
        $this->assertSame('rest', $map['2026-08-17']['state']);
        $this->assertSame('easy', $map['2026-08-18']['state']);
    }

    public function test_fatigue_extends_a_running_recovery_by_up_to_three_easy_days(): void
    {
        $race = $this->race('2026-08-14', '10 km', 10.0);
        $svc  = app(RaceRecovery::class);
        $from = CarbonImmutable::parse('2026-08-14');
        $to   = CarbonImmutable::parse('2026-08-30');

        $normal   = $svc->spread([$race], $from, $to, false, CarbonImmutable::parse(self::MONDAY));
        $extended = $svc->spread([$race], $from, $to, true, CarbonImmutable::parse(self::MONDAY));

        $this->assertCount(3, $normal);
        $this->assertCount(6, $extended);
        $this->assertSame('easy', $extended['2026-08-20']['state']);
        $this->assertTrue($extended['2026-08-20']['extended']);
    }

    public function test_fatigue_does_not_extend_a_future_race(): void
    {
        $race = $this->race('2026-08-23', '10 km', 10.0);

        $map = app(RaceRecovery::class)->spread([$race], CarbonImmutable::parse(self::MONDAY), CarbonImmutable::parse('2026-08-30'), true, CarbonImmutable::parse(self::MONDAY));

        $this->assertCount(3, $map);
    }

    public function test_without_a_race_nothing_changes(): void
    {
        $event = $this->event();

        $this->assertEquals(
            $this->build($event, [], [])['days'],
            app(WeeklyPatternService::class)->build(
                $event,
                CarbonImmutable::parse(self::MONDAY),
                CarbonImmutable::parse(self::MONDAY)->addDays(13),
                $this->availability(),
                [], [], null, null, null, null, [],
            )['days'],
        );
    }
}
