<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Event;
use App\Models\User;
use App\Services\PlanContextBuilder;
use App\Services\TrainingPaceService;
use App\Services\WeeklyVolumeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Die Zahlen, aus denen ein Trainingsplan gebaut wird — und die im Prompt
 * bisher fehlten.
 *
 * Der Planer bekam eine einzige Kennzahl (die Schwellenpace) und sollte
 * daraus Renntempo, Trainingstempi und Wochenumfänge selbst ableiten. Er
 * riet, und er riet bei jeder Neuberechnung anders. Gleichzeitig stand in
 * der Aktivitätenliste jede Radfahrt mit „Pace 2:28 min/km" — aus dieser
 * Mischung sollte eine Formeinschätzung entstehen.
 */
class PlanNumbersTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 500000;

    private function addRun(User $user, int $daysAgo, float $km, int $minutes = 0, string $type = 'Run'): void
    {
        Activity::create([
            'user_id'      => $user->id,
            'strava_id'    => $this->seq++,
            'name'         => 'Lauf',
            'type'         => $type,
            'distance'     => $km * 1000,
            'moving_time'  => ($minutes ?: (int) round($km * 5.5)) * 60,
            'elapsed_time' => ($minutes ?: (int) round($km * 5.5)) * 60,
            'start_date'   => now()->subDays($daysAgo),
        ]);
    }

    private function marathon(User $user): Event
    {
        return Event::create([
            'user_id'             => $user->id,
            'name'                => 'Berlin Marathon',
            'event_date'          => now()->addDays(38),
            'race_distance'       => 'marathon',
            'priority'            => 'A',
            'target_time_hours'   => 3,
            'target_time_minutes' => 30,
        ]);
    }

    // ── Tempi ────────────────────────────────────────────────────────────

    /** Aus 4:22 Schwellenpace und 3:30 Zielzeit werden konkrete Zahlen. */
    public function test_the_pace_table_is_derived_from_threshold_and_goal(): void
    {
        $user  = User::factory()->create();
        $paces = app(TrainingPaceService::class)->forEvent($this->marathon($user), 4 + 22 / 60);

        $this->assertSame('4:22', $paces['threshold']);
        $this->assertSame('4:58', $paces['target_pace'], 'Zielpace = 3:30 Std auf 42,195 km');
        $this->assertSame('5:08–5:43', $paces['easy']);

        // Das Ziel ist minimal langsamer als die Prognose — also machbar.
        $this->assertLessThan(0, $paces['delta_sec']);
        $this->assertStringContainsString('passt zur heutigen Form', $paces['verdict']);
    }

    /** Ein zu ehrgeiziges Ziel wird als solches benannt, nicht verschwiegen. */
    public function test_an_unrealistic_goal_is_called_out(): void
    {
        $user  = User::factory()->create();
        $event = $this->marathon($user);
        $event->update(['target_time_hours' => 2, 'target_time_minutes' => 45]);

        $paces = app(TrainingPaceService::class)->forEvent($event->refresh(), 4 + 22 / 60);

        $this->assertGreaterThan(25, $paces['delta_sec']);
        $this->assertStringContainsString('deutlich über der heutigen Form', $paces['verdict']);
    }

    /** Ohne Schwellenpace gibt es keine erfundene Tabelle. */
    public function test_without_a_threshold_there_is_no_pace_table(): void
    {
        $user = User::factory()->create();

        $this->assertNull(app(TrainingPaceService::class)->forEvent($this->marathon($user), null));
    }

    // ── Wochenumfang ─────────────────────────────────────────────────────

    public function test_weekly_volume_counts_only_runs(): void
    {
        $user = User::factory()->create();

        // Zwei volle Wochen Laufen, dazu Radfahren und ein GPS-Fehlstart.
        $monday = CarbonImmutable::today()->startOfWeek();
        foreach ([8, 10, 12] as $i => $km) {
            $this->addRun($user, (int) $monday->subWeek()->addDays($i)->diffInDays(now()), $km);
        }
        $this->addRun($user, 9, 40, 90, 'Ride');
        $this->addRun($user, 9, 0.01, 1);

        $v = app(WeeklyVolumeService::class)->forUser($user->id);

        $this->assertTrue($v['has_data']);
        $this->assertSame(30.0, $v['avg_km'], 'Rad und Fehlstart dürfen nicht mitzählen');
        $this->assertSame(12.0, $v['longest_run']);
        $this->assertSame(33.0, $v['next_week_max'], 'Höchstens 10 % mehr in der Folgewoche');
    }

    /** Die laufende Woche ist unvollständig und verfälscht jeden Schnitt. */
    public function test_the_current_week_does_not_skew_the_average(): void
    {
        $user   = User::factory()->create();
        $monday = CarbonImmutable::today()->startOfWeek();

        $this->addRun($user, (int) $monday->subWeek()->diffInDays(now()), 50);
        $this->addRun($user, 0, 5); // heute, laufende Woche

        $v = app(WeeklyVolumeService::class)->forUser($user->id);

        $this->assertSame(50.0, $v['avg_km']);
    }

    public function test_the_volume_section_names_the_ceiling(): void
    {
        $user   = User::factory()->create();
        $monday = CarbonImmutable::today()->startOfWeek();
        $this->addRun($user, (int) $monday->subWeek()->diffInDays(now()), 40);

        $text = app(WeeklyVolumeService::class)
            ->toPromptSection(app(WeeklyVolumeService::class)->forUser($user->id), $this->marathon($user));

        $this->assertStringContainsString('44 km NICHT überschreiten', $text);
        $this->assertStringContainsString('Entlastungswoche', $text);
    }

    // ── Datenhygiene ─────────────────────────────────────────────────────

    /**
     * Radfahrten mit „Pace 2:28 min/km" und Spaziergänge mit 13:20 standen
     * in derselben Liste, aus der das Modell die Laufform ablesen sollte.
     */
    public function test_only_runs_reach_the_activity_list(): void
    {
        $user = User::factory()->create();
        $user->runnerProfile()->create(['threshold_speed' => 4 + 22 / 60]);

        $this->addRun($user, 2, 10);
        $this->addRun($user, 3, 25, 60, 'Ride');
        $this->addRun($user, 4, 1.2, 16, 'Walk');
        $this->addRun($user, 5, 0.01, 1);

        $context = app(PlanContextBuilder::class)->build($user->refresh(), $this->marathon($user));

        $this->assertCount(1, $context->recentActivities);
        $this->assertSame(10.0, $context->recentActivities[0]['distance_km']);

        // Verschwunden ist es nicht — es steht getrennt, als das was es ist.
        $this->assertArrayHasKey('Ride', $context->crossTraining);
        $this->assertSame(1, $context->crossTraining['Ride']['count']);
    }

    /** Und die berechneten Zahlen liegen im Kontext bereit. */
    public function test_the_context_carries_paces_and_volume(): void
    {
        $user = User::factory()->create();
        $user->runnerProfile()->create(['threshold_speed' => 4 + 22 / 60]);
        $this->addRun($user, 8, 12);

        $context = app(PlanContextBuilder::class)->build($user->refresh(), $this->marathon($user));

        $this->assertSame('4:58', $context->paces['target_pace']);
        $this->assertTrue($context->volume['has_data']);
    }
}
