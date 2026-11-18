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

    private function fakeLoad(float $atl, float $ctl, bool $enough): void
    {
        $this->app->instance(TrainingLoadService::class, new class($atl, $ctl, $enough) extends TrainingLoadService {
            public function __construct(private float $a, private float $c, private bool $e) {}

            public function calculate(int $userId, bool $withoutStrava = false): array
            {
                return ['atl' => $this->a, 'ctl' => $this->c, 'tsb' => $this->c - $this->a, 'enough_data' => $this->e];
            }
        });
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
}
