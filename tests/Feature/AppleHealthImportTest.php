<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\GarminConnection;
use App\Models\GarminDailyMetric;
use App\Models\StravaAccount;
use App\Models\User;
use App\Services\GarminHealthSummary;
use App\Services\VdotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Apple Health aus der iOS-App, Stufe 2 (08.11.2026): Erholungswerte und
 * Trainings an den Server, nur mit Einwilligung.
 */
class AppleHealthImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Carbon::setTestNow('2026-11-08 18:00:00');

        $this->user = User::factory()->onboarded()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function consent(): void
    {
        $this->user->forceFill(['apple_health_consent_at' => now()])->save();
    }

    private function day(array $values = []): array
    {
        return $values + [
            'date'        => '2026-11-08',
            'sleep_min'   => 450,
            'hrv_ms'      => 52,
            'resting_hr'  => 48,
            'vo2max'      => 53.4,
            'spo2'        => 96.5,
            'respiration' => 14.2,
        ];
    }

    /** 30 min Lauf, 6 km, eine Rundenmarke nach 3 km, Strecke je Minute. */
    private function watchRun(string $start = '2026-11-08T16:00:00Z'): array
    {
        $stream = [];
        for ($i = 0; $i <= 30; $i++) {
            $stream[] = [$i * 60, $i * 200];
        }

        return [
            'start'       => $start,
            'end'         => Carbon::parse($start)->addMinutes(30)->toIso8601String(),
            'type'        => 'running',
            'duration_s'  => 1800,
            'distance_m'  => 6000,
            'avg_hr'      => 148.4,
            'max_hr'      => 171,
            'source_name' => 'Apple Watch von Jan',
            'laps'        => [
                ['duration_s' => 900, 'distance_m' => 3000, 'avg_hr' => 142, 'max_hr' => 155],
                ['duration_s' => 900, 'distance_m' => 3000, 'avg_hr' => 155, 'max_hr' => 171],
            ],
            'stream'      => $stream,
        ];
    }

    public function test_nothing_is_stored_without_consent(): void
    {
        $this->actingAs($this->user)
            ->postJson('/health/apple/metrics', ['days' => [$this->day()]])
            ->assertOk()
            ->assertJson(['accepted' => false]);

        $this->assertSame(0, GarminDailyMetric::count());
    }

    public function test_consent_needs_the_checkbox(): void
    {
        $this->actingAs($this->user)
            ->patch('/health/apple/consent', ['enabled' => true])
            ->assertSessionHasErrors('ai_consent');

        $this->assertNull($this->user->fresh()->apple_health_consent_at);

        $this->actingAs($this->user)
            ->patch('/health/apple/consent', ['enabled' => true, 'ai_consent' => true])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($this->user->fresh()->apple_health_consent_at);
    }

    /** Freigeben geht schon im Verbinden-Schritt des Onboardings (09.11.2026). */
    public function test_consent_works_during_onboarding(): void
    {
        $fresh = User::factory()->create(['onboarding_completed_at' => null]);

        $this->actingAs($fresh)
            ->from('/onboarding')
            ->patch('/health/apple/consent', ['enabled' => true, 'ai_consent' => true])
            ->assertRedirect('/onboarding');

        $this->assertNotNull($fresh->fresh()->apple_health_consent_at);

        $this->actingAs($fresh)
            ->postJson('/health/apple/metrics', ['days' => [$this->day()]])
            ->assertOk()
            ->assertJson(['accepted' => true]);

        $this->actingAs($fresh)->get('/onboarding')
            ->assertInertia(fn ($page) => $page
                ->where('connections.appleHealth.watch_metrics', false)
                ->whereNot('connections.appleHealth.consent_at', null)
                ->where('connections.source.current', 'apple'));
    }

    public function test_metrics_land_in_the_daily_metrics_as_apple(): void
    {
        $this->consent();

        $this->actingAs($this->user)
            ->postJson('/health/apple/metrics', ['days' => [$this->day(), $this->day(['date' => '2026-11-07', 'spo2' => null])]])
            ->assertOk()
            ->assertJson(['accepted' => true]);

        $row = GarminDailyMetric::where('user_id', $this->user->id)->whereDate('date', '2026-11-08')->first();
        $this->assertSame('apple', $row->source);
        $this->assertSame(7.5, $row->sleep_hours);
        $this->assertSame(52.0, $row->hrv);
        $this->assertSame(48, $row->resting_hr);
        $this->assertSame(96.5, $row->spo2);
        $this->assertSame(14.2, $row->respiration_rate);
        $this->assertNotNull($this->user->fresh()->apple_health_synced_at);
        $this->assertTrue($this->user->fresh()->receivesHealthMetrics());

        // Ein Tag ohne Wert bleibt ohne Wert, nicht 0.
        $this->assertNull(GarminDailyMetric::whereDate('date', '2026-11-07')->first()->spo2);
    }

    public function test_a_watch_with_its_own_connection_wins(): void
    {
        $this->consent();
        GarminDailyMetric::create(['user_id' => $this->user->id, 'date' => '2026-11-01', 'source' => 'apple', 'hrv' => 40]);
        GarminConnection::create([
            'user_id'                 => $this->user->id,
            'garmin_user_id'          => 'g-1',
            'access_token'            => 'a',
            'refresh_token'           => 'r',
            'access_token_expires_at' => now()->addHours(20),
            'permissions'             => [GarminConnection::HEALTH],
            'ai_consent_at'           => now(),
        ]);
        GarminDailyMetric::create(['user_id' => $this->user->id, 'date' => '2026-11-08', 'source' => 'garmin', 'hrv' => 70]);

        $this->actingAs($this->user->fresh())
            ->postJson('/health/apple/metrics', ['days' => [$this->day()]])
            ->assertJson(['accepted' => false]);

        $this->assertSame(['garmin'], GarminDailyMetric::where('user_id', $this->user->id)->pluck('source')->all());
        $this->assertSame(70.0, GarminDailyMetric::first()->hrv);
    }

    public function test_revoking_deletes_the_metrics_but_keeps_the_activities(): void
    {
        $this->consent();
        GarminDailyMetric::create(['user_id' => $this->user->id, 'date' => '2026-11-08', 'source' => 'apple', 'hrv' => 50]);
        $this->actingAs($this->user)->postJson('/health/apple/workouts', ['workouts' => [$this->watchRun()]])->assertJson(['accepted' => true]);

        $this->actingAs($this->user)
            ->patch('/health/apple/consent', ['enabled' => false])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->user->fresh()->apple_health_consent_at);
        $this->assertSame(0, GarminDailyMetric::count());
        $this->assertSame(1, Activity::where('source', 'apple')->count());
    }

    public function test_a_watch_run_becomes_an_activity(): void
    {
        $this->consent();

        $this->assertSame(Activity::SOURCE_APPLE, $this->user->fresh()->activitySource());

        $this->actingAs($this->user)
            ->postJson('/health/apple/workouts', ['workouts' => [$this->watchRun()]])
            ->assertOk()
            ->assertJson(['accepted' => true, 'outcomes' => ['imported' => 1]]);

        $activity = Activity::where('user_id', $this->user->id)->sole();
        $this->assertSame('apple', $activity->source);
        $this->assertSame('Run', $activity->type);
        $this->assertSame('Lauf', $activity->name);
        $this->assertSame(Carbon::parse('2026-11-08T16:00:00Z')->timestamp, (int) $activity->apple_start);
        $this->assertEquals(6000, $activity->distance);
        $this->assertSame(1800, (int) $activity->moving_time);
        $this->assertEquals(148.4, $activity->average_heartrate);
        $this->assertSame('Apple Watch von Jan', $activity->device_name);
        $this->assertCount(2, $activity->laps);
        $this->assertEquals(155, $activity->laps[1]['average_heartrate']);

        // Bestzeiten aus der Streckenreihe: 1 km und 5 km in 5:00/km.
        $fiveK = $activity->bestEfforts()->where('distance_m', 5000)->first();
        $this->assertNotNull($fiveK);
        $this->assertSame(1500, (int) $fiveK->elapsed_time);

        // Derselbe Lauf noch einmal: nichts Neues.
        $this->actingAs($this->user)
            ->postJson('/health/apple/workouts', ['workouts' => [$this->watchRun()]])
            ->assertJson(['outcomes' => ['imported' => 0, 'known' => 1]]);
        $this->assertSame(1, Activity::count());
    }

    public function test_workouts_are_ignored_while_another_source_delivers(): void
    {
        $this->consent();
        StravaAccount::create([
            'user_id'          => $this->user->id,
            'strava_id'        => 4711,
            'access_token'     => 'tok',
            'refresh_token'    => 'ref',
            'token_expires_at' => now()->addDay(),
        ]);

        $user = $this->user->fresh();
        $this->assertSame(['strava', 'apple'], $user->usableActivitySources());
        $this->assertSame('strava', $user->activitySource());

        $this->actingAs($user)
            ->postJson('/health/apple/workouts', ['workouts' => [$this->watchRun()]])
            ->assertJson(['accepted' => false]);

        $this->assertSame(0, Activity::count());
    }

    public function test_the_app_learns_what_to_send(): void
    {
        $this->actingAs($this->user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('auth.appleHealth', null));

        $this->consent();

        $this->actingAs($this->user->fresh())->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('auth.appleHealth.metrics', true)
                ->where('auth.appleHealth.workouts', true)
                ->where('auth.appleHealth.metrics_synced', false));
    }

    public function test_apple_vo2max_names_its_source(): void
    {
        $this->consent();
        GarminDailyMetric::create(['user_id' => $this->user->id, 'date' => '2026-11-07', 'source' => 'apple', 'vo2max' => 53.4]);

        $info = app(VdotService::class)->forProfile($this->user->runnerProfile ?? $this->user->runnerProfile()->create([]));

        $this->assertSame('garmin', $info['source']);
        $this->assertStringContainsString('Apple Health', $info['label']);
    }

    public function test_breathing_and_oxygen_reach_the_plan_summary(): void
    {
        foreach (range(1, 20) as $i) {
            GarminDailyMetric::create([
                'user_id' => $this->user->id, 'date' => now()->subDays($i)->toDateString(), 'source' => 'apple',
                'respiration_rate' => $i <= 7 ? 16.0 : 14.0, 'spo2' => 96.0,
            ]);
        }

        $summary = app(GarminHealthSummary::class)->forUser($this->user->id, CarbonImmutable::parse('2026-11-08'));

        $this->assertTrue(collect($summary['lines'])->contains(fn ($l) => str_starts_with($l, 'Atemfrequenz nachts')));
        $this->assertTrue(collect($summary['lines'])->contains(fn ($l) => str_starts_with($l, 'Blutsauerstoff: 96')));
        $this->assertContains('Atemfrequenz liegt über der Grundlinie', $summary['flags']);
    }
}
