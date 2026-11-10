<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\User;
use App\Services\Polyline;
use App\Services\Today\TodayExtras;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * „Heute“ mit mehr Leben (Mockup vom 10.11.2026): der Coach-Satz und die
 * Route des letzten Laufs kommen aus den Daten, ohne Modellaufruf.
 */
class TodayExtrasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 12:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function lauf(User $user, string $date, float $km, float $paceSec, ?int $hr, ?array $points = null): Activity
    {
        static $id = 1000;

        return Activity::create([
            'user_id' => $user->id, 'strava_id' => ++$id, 'name' => 'Lauf', 'type' => 'Run',
            'distance' => $km * 1000, 'moving_time' => (int) ($km * $paceSec), 'elapsed_time' => (int) ($km * $paceSec),
            'average_speed' => 1000 / $paceSec, 'average_heartrate' => $hr, 'start_date' => $date,
            'polyline' => $points ? ['polyline' => Polyline::encode($points)] : null,
        ]);
    }

    public function test_the_coach_names_a_lower_heart_rate_at_the_same_pace(): void
    {
        $user = User::factory()->create();
        $this->lauf($user, '2026-10-13 08:00', 10, 330, 152);
        $this->lauf($user, '2026-11-09 08:00', 12, 332, 144);

        $line = app(TodayExtras::class)->coachLine($user, [], null);

        $this->assertStringStartsWith('Gestern 12 km in 5:32 bei Puls 144.', $line);
        $this->assertStringContainsString('bei 152. Deine Grundlage wächst.', $line);
    }

    public function test_without_a_comparison_the_coach_talks_about_the_week(): void
    {
        $user = User::factory()->create();
        $this->lauf($user, '2026-11-09 08:00', 12, 332, 150);

        $week = [
            ['type' => 'easy_run', 'distance_km' => 8.0, 'status' => 'completed', 'sport_type' => null],
            ['type' => 'tempo_run', 'distance_km' => 11.0, 'status' => 'planned', 'sport_type' => null],
            ['type' => 'rest', 'distance_km' => null, 'status' => 'planned', 'sport_type' => null],
        ];

        $this->assertSame('Diese Woche 8 von 19 km. Noch eine Einheit, dann steht die Woche.',
            app(TodayExtras::class)->coachLine($user, $week, null));
    }

    public function test_before_the_plan_starts_the_week_is_not_called_done(): void
    {
        // Gemeldet am 10.11.2026 (Dienstag): Plan ab 30.11., gelaufen 4,2 km,
        // der Coach sagte „Diese Woche steht". Vor dem Planstart kennt die
        // Woche nur, was gelaufen wurde.
        $user = User::factory()->create();
        $week = [['date' => '2026-11-09', 'type' => 'easy_run', 'distance_km' => 4.2, 'status' => 'completed', 'sport_type' => null]];

        $this->assertSame('Diese Woche bisher 4,2 km gelaufen.', app(TodayExtras::class)->coachLine($user, $week, null));

        $week[] = ['date' => '2026-11-15', 'type' => 'rest', 'distance_km' => null, 'status' => 'planned', 'sport_type' => null];
        $this->assertSame('Diese Woche steht: 4,2 km gelaufen. Gut gemacht.', app(TodayExtras::class)->coachLine($user, $week, null));
    }

    public function test_the_last_run_is_drawn_inside_its_frame(): void
    {
        $user = User::factory()->create();
        $points = [];
        for ($i = 0; $i <= 40; $i++) {
            $points[] = [52.37 + 0.01 * sin($i / 6), 9.73 + 0.03 * cos($i / 6)];
        }
        $this->lauf($user, '2026-11-07 09:00', 18.4, 331, 142, $points);

        $run = app(TodayExtras::class)->lastRun($user);

        $this->assertSame(18.4, $run['km']);
        preg_match_all('/[ML]([\d.]+) ([\d.]+)/', $run['path'], $m);
        $this->assertCount(41, $m[1]);
        foreach ($m[1] as $i => $x) {
            $this->assertTrue($x >= 27.9 && $x <= 272.1 && $m[2][$i] >= 27.9 && $m[2][$i] <= 172.1, "Punkt {$i} liegt außerhalb");
        }
    }

    public function test_a_run_without_a_route_is_not_shown(): void
    {
        $user = User::factory()->create();
        $this->lauf($user, '2026-11-07 09:00', 8, 330, 140);

        $this->assertNull(app(TodayExtras::class)->lastRun($user));
    }
}
