<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\GarminDailyMetric;
use App\Models\RunnerProfile;
use App\Models\SessionRecommendation;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WellbeingEntry;
use App\Services\Polar\PolarHealthImporter;
use App\Services\Readiness\ReadinessProposal;
use App\Services\Readiness\ReadinessService;
use App\Services\TrainingLoadService;
use App\Services\Widget\WidgetPayload;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zone3s Readiness (seit 18.11.2026). Jans Garmin zeigte Trainings­bereitschaft 1
 * und empfahl einen Ruhetag; Zone3 schlug einen lockeren Lauf vor, weil der
 * Tagesvorschlag die Werte der Uhr gar nicht sah.
 */
class ReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 07:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function athlete(): User
    {
        $user = User::factory()->onboarded()->create();
        // Apple Health ist der einfachste Weg zu receivesHealthMetrics().
        $user->forceFill(['apple_health_consent_at' => now()])->save();
        RunnerProfile::create(['user_id' => $user->id, 'threshold_speed' => 4.25]);

        // Drei Wochen gewohnte Werte: HRV um 60, Ruhepuls um 50, 7,5 h Schlaf.
        for ($d = 21; $d >= 1; $d--) {
            GarminDailyMetric::create([
                'user_id' => $user->id, 'date' => now()->subDays($d)->toDateString(), 'source' => 'apple',
                'hrv' => 58 + ($d % 5), 'resting_hr' => 49 + ($d % 3), 'sleep_hours' => 7.5,
            ]);
        }

        return $user;
    }

    private function tonight(User $user, array $values): void
    {
        GarminDailyMetric::create(['user_id' => $user->id, 'date' => now()->toDateString(), 'source' => 'apple'] + $values);
    }

    private function readiness(User $user): ?array
    {
        return app(ReadinessService::class)->forDay($user, CarbonImmutable::today());
    }

    private function plannedToday(User $user, string $type = 'easy_run'): TrainingSession
    {
        $event = Event::create([
            'user_id' => $user->id, 'name' => 'Zielrennen', 'event_date' => now()->addDays(60),
            'race_distance' => 'half_marathon', 'priority' => 'A',
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);
        $plan->forceFill(['is_active' => true])->save();

        return TrainingSession::create([
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $event->id,
            'planned_date' => now()->toDateString(), 'type' => $type, 'title' => 'Geplant',
            'distance_km' => 10, 'duration_min' => 55, 'intensity' => $type === 'easy_run' ? 'low' : 'high',
            'status' => 'planned',
        ]);
    }

    public function test_no_value_without_a_measurement_from_tonight(): void
    {
        $user = $this->athlete();

        $this->assertNull($this->readiness($user));
    }

    public function test_no_value_without_a_watch(): void
    {
        $user = User::factory()->onboarded()->create();
        WellbeingEntry::create(['user_id' => $user->id, 'date' => now()->toDateString(), 'energy_level' => 2,
            'sleep_quality' => 2, 'muscle_soreness' => 9, 'stress_level' => 9, 'mood' => 3]);

        $this->assertNull($this->readiness($user));
    }

    public function test_a_bad_night_means_rest(): void
    {
        $user = $this->athlete();
        $this->tonight($user, ['hrv' => 40, 'resting_hr' => 58, 'sleep_hours' => 4.5]);

        $r = $this->readiness($user);

        $this->assertLessThan(ReadinessService::REST_BELOW, $r['score']);
        $this->assertSame('poor', $r['level']);
        $this->assertStringContainsString('HRV', $r['reasons'][0]);
        $this->assertContains('kurze Nacht (4,5 h)', $r['reasons']);
    }

    public function test_a_normal_night_is_ready(): void
    {
        $user = $this->athlete();
        $this->tonight($user, ['hrv' => 62, 'resting_hr' => 49, 'sleep_hours' => 8]);

        $r = $this->readiness($user);

        $this->assertGreaterThanOrEqual(ReadinessService::EASY_BELOW, $r['score']);
        $this->assertSame([], $r['reasons']);
    }

    public function test_sick_caps_the_value(): void
    {
        $user = $this->athlete();
        $this->tonight($user, ['hrv' => 62, 'resting_hr' => 49, 'sleep_hours' => 8]);
        WellbeingEntry::create(['user_id' => $user->id, 'date' => now()->toDateString(), 'energy_level' => 5,
            'sleep_quality' => 5, 'muscle_soreness' => 3, 'stress_level' => 3, 'mood' => 5, 'is_sick' => true]);

        $r = $this->readiness($user);

        $this->assertLessThanOrEqual(10, $r['score']);
        $this->assertSame('du bist krank', $r['reasons'][0]);
    }

    public function test_low_readiness_proposes_a_rest_day_once(): void
    {
        $user    = $this->athlete();
        $session = $this->plannedToday($user);
        $this->tonight($user, ['hrv' => 40, 'resting_hr' => 58, 'sleep_hours' => 4.5]);

        $rec = app(ReadinessProposal::class)->check($user);

        $this->assertNotNull($rec);
        $this->assertSame(SessionRecommendation::SOURCE_READINESS, $rec->source);
        $this->assertSame('rest', $rec->after['type']);
        $this->assertSame('easy_run', $session->fresh()->type, 'nichts ändert sich vor dem Annehmen');

        $this->assertNull(app(ReadinessProposal::class)->check($user), 'höchstens ein Vorschlag am Tag');

        $rec->accept();
        $this->assertSame('rest', $session->fresh()->type);
        $this->assertNotNull($session->fresh()->pinned_at);
    }

    public function test_good_readiness_proposes_nothing(): void
    {
        $user = $this->athlete();
        $this->plannedToday($user, 'interval');
        $this->tonight($user, ['hrv' => 62, 'resting_hr' => 49, 'sleep_hours' => 8]);

        $this->assertNull(app(ReadinessProposal::class)->check($user));
    }

    public function test_the_daily_suggestion_is_a_rest_day_without_a_model_call(): void
    {
        $user = $this->athlete();
        $this->tonight($user, ['hrv' => 40, 'resting_hr' => 58, 'sleep_hours' => 4.5]);

        // Http::preventStrayRequests() würde jeden Modellaufruf scheitern lassen.
        $rec = $this->actingAs($user)->getJson('/api/ai/recommendation/today')->assertOk()->json('recommendation');

        $this->assertSame('rest', $rec['type']);
        $this->assertTrue($rec['readiness_rest']);
    }

    public function test_widget_carries_the_readiness(): void
    {
        $user = $this->athlete();
        $this->tonight($user, ['hrv' => 40, 'resting_hr' => 58, 'sleep_hours' => 4.5]);

        $payload = app(WidgetPayload::class)->build($user);

        $this->assertSame('danger', $payload['readiness']['tone']);
        $this->assertSame('2026-11-18', $payload['readiness']['date']);
    }

    public function test_polar_recharge_status_is_read(): void
    {
        $out = app(PolarHealthImporter::class)->recharge(['recharges' => [
            ['date' => '2026-11-18', 'heart_rate_variability_avg' => 50, 'nightly_recharge_status' => 2],
            ['date' => '2026-11-17', 'nightly_recharge_status' => 9],
        ]]);

        $this->assertSame(2, $out['2026-11-18']['recharge_status']);
        $this->assertNull($out['2026-11-17']['recharge_status']);
    }

    private function fakeLoad(float $atl, float $ctl, bool $enough, array $history = []): void
    {
        $this->app->instance(TrainingLoadService::class, new class($atl, $ctl, $enough, $history) extends TrainingLoadService {
            public function __construct(private float $a, private float $c, private bool $e, private array $h) {}

            public function calculate(int $userId, bool $withoutStrava = false): array
            {
                return ['atl' => $this->a, 'ctl' => $this->c, 'tsb' => $this->c - $this->a, 'enough_data' => $this->e, 'history' => $this->h];
            }
        });
    }

    /** Verlauf wie TrainingLoadService: zehn Tage ohne Daten, dann die TSS je Tag bis gestern. */
    private function fakeHistory(array $tss): void
    {
        $ctl = $atl = 0.0;
        $rows = [];
        $values = array_merge(array_fill(0, 10, 0.0), $tss);
        $day = CarbonImmutable::today()->subDays(count($values));
        foreach ($values as $t) {
            $ctl += ($t - $ctl) / 42;
            $atl += ($t - $atl) / 7;
            $rows[] = ['date' => $day->toDateString(), 'ctl' => round($ctl, 1), 'atl' => round($atl, 1), 'tss' => $t];
            $day = $day->addDay();
        }
        $this->fakeLoad($atl, $ctl, true, $rows);
    }

    /**
     * Jan, 27.11.2026: Garmin 95, Zone3 75 mit Belastung 0. Nach einem Monat
     * Uhr-Daten ist die CTL nur halb eingelaufen; gleichmäßiges Training ist
     * keine Überlastung.
     */
    public function test_steady_training_after_a_month_of_data_is_no_overload(): void
    {
        $this->fakeHistory(array_merge(...array_fill(0, 15, [55.0, 0.0])));
        $user = $this->athlete();
        $this->tonight($user, ['hrv' => 62, 'resting_hr' => 49, 'sleep_hours' => 8]);

        $r = $this->readiness($user);

        $this->assertGreaterThanOrEqual(60, $r['parts']['load']);
        $this->assertNotContains('hohe Belastung der letzten Tage', $r['reasons']);
    }

    /** 27.11.2026: ein Drittel über der gewohnten Last ist Aufbau, keine Überlastung (Warnung erst ab ACWR 1,5). */
    public function test_a_third_above_the_usual_load_is_no_alarm(): void
    {
        $this->fakeLoad(atl: 32, ctl: 24, enough: true);
        $user = $this->athlete();
        $this->tonight($user, ['hrv' => 62, 'resting_hr' => 49, 'sleep_hours' => 8]);

        $r = $this->readiness($user);

        $this->assertSame(50, $r['parts']['load']);
        $this->assertNotContains('hohe Belastung der letzten Tage', $r['reasons']);
    }

    /** Der Anstieg nach der Pause bis 17.11.2026 (Garmin 1 am 18.11.) bleibt eine Überlastung. */
    public function test_a_real_ramp_after_a_break_still_counts(): void
    {
        $this->fakeHistory([4.3, 4.7, 2, 0, 0, 0, 0, 0, 0, 9.9, 0, 7, 41, 44.6, 0, 63.4, 23.6, 11.5, 54.8, 0, 97.8]);
        $user = $this->athlete();
        $this->tonight($user, ['hrv' => 62, 'resting_hr' => 49, 'sleep_hours' => 8]);

        $r = $this->readiness($user);

        $this->assertLessThan(37, $r['parts']['load']);
        $this->assertContains('hohe Belastung der letzten Tage', $r['reasons']);
    }

    /** Jans Morgen am 18.11.2026: Garmin 1, Zone3 47 — guter Schlaf hob HRV und Ruhepuls auf. */
    public function test_two_warning_signs_cap_the_value_despite_good_sleep(): void
    {
        $user = $this->athlete();
        $this->tonight($user, ['hrv' => 44, 'resting_hr' => 56, 'sleep_hours' => 8.5]);
        WellbeingEntry::create(['user_id' => $user->id, 'date' => now()->toDateString(), 'energy_level' => 8,
            'sleep_quality' => 8, 'muscle_soreness' => 1, 'stress_level' => 1, 'mood' => 8]);

        $r = $this->readiness($user);

        $this->assertCount(2, $r['reasons']);
        $this->assertLessThan(ReadinessService::REST_BELOW, $r['score']);
    }

    public function test_load_counts_early_when_acute_load_doubles(): void
    {
        $this->fakeLoad(atl: 27, ctl: 8, enough: false);
        $user = $this->athlete();
        $this->tonight($user, ['hrv' => 62, 'resting_hr' => 49, 'sleep_hours' => 8]);

        $r = $this->readiness($user);

        $this->assertSame(0, $r['parts']['load']);
        $this->assertContains('hohe Belastung der letzten Tage', $r['reasons']);
    }

    public function test_load_stays_out_early_without_a_clear_ramp(): void
    {
        $this->fakeLoad(atl: 12, ctl: 4, enough: false);
        $user = $this->athlete();
        $this->tonight($user, ['hrv' => 62, 'resting_hr' => 49, 'sleep_hours' => 8]);

        $this->assertArrayNotHasKey('load', $this->readiness($user)['parts']);
    }

    private function runToday(User $user, string $start, int $minutes = 60, float $speed = 3.9): void
    {
        \App\Models\Activity::create([
            'user_id' => $user->id, 'source' => \App\Models\Activity::SOURCE_GARMIN, 'garmin_activity_id' => random_int(1, 1_000_000),
            'name' => 'Tempo', 'type' => 'Run', 'start_date' => now()->toDateString() . ' ' . $start,
            'distance' => $speed * $minutes * 60, 'moving_time' => $minutes * 60, 'elapsed_time' => $minutes * 60, 'average_speed' => $speed,
        ]);
    }

    /** Über den Tag (26.11.2026): Training heute zieht den aktuellen Wert, nicht den Morgenwert. */
    public function test_training_today_lowers_the_current_value_only(): void
    {
        $user = $this->athlete();
        $this->tonight($user, ['hrv' => 62, 'resting_hr' => 49, 'sleep_hours' => 8]);
        $before = $this->readiness($user)['score'];

        $this->runToday($user, '07:30:00');
        Carbon::setTestNow('2026-11-18 09:00:00');

        $service = app(ReadinessService::class);
        $now     = $service->forDay($user, CarbonImmutable::today());
        $morning = $service->morning($user, CarbonImmutable::today());

        $this->assertSame($before, $morning['score']);
        $this->assertLessThan($morning['score'] - 10, $now['score']);
        $this->assertArrayHasKey('training', $now['today']);
        $this->assertContains('Training heute', $now['reasons']);
    }

    public function test_the_drop_from_training_recovers_over_the_day(): void
    {
        $user = $this->athlete();
        $this->tonight($user, ['hrv' => 62, 'resting_hr' => 49, 'sleep_hours' => 8]);
        $this->runToday($user, '07:30:00');

        Carbon::setTestNow('2026-11-18 09:00:00');
        $soon = app(ReadinessService::class)->forDay($user, CarbonImmutable::today())['score'];
        Carbon::setTestNow('2026-11-18 21:00:00');
        $late = (new ReadinessService(app(TrainingLoadService::class)))->forDay($user, CarbonImmutable::today())['score'];

        $this->assertGreaterThan($soon, $late);
    }

    public function test_a_training_that_has_not_ended_does_not_count_yet(): void
    {
        $user = $this->athlete();
        $this->tonight($user, ['hrv' => 62, 'resting_hr' => 49, 'sleep_hours' => 8]);
        $this->runToday($user, '06:30:00', minutes: 60); // endet 07:30, jetzt 07:00

        $this->assertSame([], $this->readiness($user)['today']);
    }

    public function test_body_battery_below_the_high_of_the_day_lowers_and_recharging_raises(): void
    {
        Carbon::setTestNow('2026-11-18 15:00:00');
        $user = $this->athlete();
        $this->tonight($user, ['hrv' => 62, 'resting_hr' => 49, 'sleep_hours' => 8,
            'body_battery_high' => 95, 'body_battery_now' => 30, 'body_battery_at' => now()->subHour()]);

        $low = $this->readiness($user);
        $this->assertSame(-13, $low['today']['body_battery']);

        GarminDailyMetric::where('user_id', $user->id)->whereDate('date', now()->toDateString())
            ->update(['body_battery_now' => 70, 'body_battery_at' => now()]);
        $high = (new ReadinessService(app(TrainingLoadService::class)))->forDay($user, CarbonImmutable::today());

        $this->assertGreaterThan($low['score'], $high['score']);
        $this->assertSame($low['score'], app(ReadinessService::class)->morning($user, CarbonImmutable::today())['score'] - 13);
    }

    public function test_a_stale_body_battery_does_not_count(): void
    {
        Carbon::setTestNow('2026-11-18 20:00:00');
        $user = $this->athlete();
        $this->tonight($user, ['hrv' => 62, 'resting_hr' => 49, 'sleep_hours' => 8,
            'body_battery_high' => 95, 'body_battery_now' => 30, 'body_battery_at' => now()->subHours(8)]);

        $this->assertSame([], $this->readiness($user)['today']);
    }

    /** Jan: der Plan ändert sich nicht von selbst, nur weil der Wert über den Tag fällt. */
    public function test_proposals_use_the_morning_value(): void
    {
        $user = $this->athlete();
        $this->tonight($user, ['hrv' => 57, 'resting_hr' => 51, 'sleep_hours' => 6.8]);
        $this->plannedToday($user, 'interval');
        $this->runToday($user, '05:00:00', minutes: 90, speed: 4.2);

        $this->assertLessThan(ReadinessService::EASY_BELOW, $this->readiness($user)['score'], 'der aktuelle Wert ist niedrig');
        $this->assertNull(app(ReadinessProposal::class)->check($user));
    }
}
