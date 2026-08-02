<?php

namespace Tests\Feature;

use App\Models\LiveTrack;
use App\Models\User;
use App\Services\LiveTrackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiveTrackTest extends TestCase
{
    use RefreshDatabase;

    private function track(array $attrs = []): LiveTrack
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        return LiveTrack::create(array_merge([
            'user_id'           => $user->id,
            'slug'              => LiveTrack::newSlug(),
            'title'             => 'Backyard Ultra',
            'starts_at'         => now()->subHours(2)->subMinutes(20),
            'yard_km'           => 6.706,
            'target_yards'      => 24,
            'garmin_session_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'garmin_token'      => 'SECRET-LIVETRACK-TOKEN',
        ], $attrs));
    }

    // ── Öffentliche Seite ────────────────────────────────────────────────

    public function test_public_page_is_reachable_without_login(): void
    {
        $track = $this->track();

        $this->get(route('live.show', $track->slug))->assertOk();
    }

    public function test_unknown_slug_is_not_found(): void
    {
        $this->get('/live/gibtesnicht')->assertNotFound();
    }

    /**
     * Der Token darf die Seite unter keinen Umständen verlassen. Genau
     * deshalb wird die Karte selbst gezeichnet statt Garmins Seite
     * einzubetten — deren Adresse traegt den Token.
     */
    public function test_public_payload_never_exposes_the_garmin_token(): void
    {
        $track = $this->track();

        $this->get(route('live.show', $track->slug))
            ->assertOk()
            ->assertDontSee('SECRET-LIVETRACK-TOKEN');

        $this->getJson(route('live.data', $track->slug))
            ->assertOk()
            ->assertDontSee('SECRET-LIVETRACK-TOKEN');
    }

    /** Ebenso wenig darf der Name des Läufers auftauchen. */
    public function test_public_payload_does_not_expose_the_runner(): void
    {
        $track = $this->track();
        $track->user->update(['name' => 'Maximilian Musterlaeufer']);

        $this->get(route('live.show', $track->slug))
            ->assertOk()
            ->assertDontSee('Maximilian Musterlaeufer');
    }

    // ── Verwaltung ───────────────────────────────────────────────────────

    public function test_manage_page_requires_login(): void
    {
        $this->get(route('live.manage'))->assertRedirect(route('login'));
    }

    public function test_livetrack_url_is_split_into_session_and_token(): void
    {
        $track = new LiveTrack();

        $ok = $track->setLiveTrackUrl(
            'https://livetrack.garmin.com/session/303b55a5-8cce-8459-b960-c279aaddfd01/token/CEDC12B6BA8BC64226D91D4AE8855'
        );

        $this->assertTrue($ok);
        $this->assertSame('303b55a5-8cce-8459-b960-c279aaddfd01', $track->garmin_session_id);
        $this->assertSame('CEDC12B6BA8BC64226D91D4AE8855', $track->garmin_token);
    }

    public function test_a_nonsense_url_is_rejected(): void
    {
        $track = new LiveTrack();

        $this->assertFalse($track->setLiveTrackUrl('https://example.com/irgendwas'));
    }

    // ── Abruf ────────────────────────────────────────────────────────────

    /**
     * Garmins Antwort ist nirgends verbindlich dokumentiert. Dieser Test
     * hält die Feldnamen fest, gegen die der Parser gebaut ist.
     */
    public function test_polling_reads_distance_heart_rate_and_pace(): void
    {
        $track = $this->track();

        Http::fake([
            'livetrack.garmin.com/*' => Http::response([
                'trackPoints' => [
                    [
                        'position'         => ['lat' => 52.5200, 'lon' => 13.4050],
                        'dateTime'         => '2026-08-08T10:00:00.000Z',
                        'fitnessPointData' => [
                            'totalDistanceMeters'  => 6706.0,
                            'speedMetersPerSecond' => 2.9,
                            'heartRateBeatsPerMin' => 142,
                        ],
                    ],
                    [
                        'position'         => ['lat' => 52.5210, 'lon' => 13.4060],
                        'dateTime'         => '2026-08-08T11:00:00.000Z',
                        'fitnessPointData' => [
                            'totalDistanceMeters'  => 13412.0,
                            'speedMetersPerSecond' => 2.7,
                            'heartRateBeatsPerMin' => 151,
                        ],
                    ],
                ],
            ]),
        ]);

        app(LiveTrackService::class)->poll($track);
        $track->refresh();

        $this->assertNull($track->last_error);
        $this->assertEquals(13412.0, $track->state['distanceM']);
        $this->assertSame(151, $track->state['hr']);
        $this->assertCount(2, $track->series);

        // 2,7 m/s entsprechen 370 s/km
        $this->assertSame(370, $track->series[1]['p']);
    }

    /** Eine nackte Liste ohne trackPoints-Hülle muss ebenso funktionieren. */
    public function test_polling_accepts_a_bare_array_response(): void
    {
        $track = $this->track();

        Http::fake([
            'livetrack.garmin.com/*' => Http::response([
                [
                    'dateTime'         => '2026-08-08T10:00:00.000Z',
                    'fitnessPointData' => ['totalDistanceMeters' => 500.0, 'heartRateBeatsPerMin' => 120],
                ],
            ]),
        ]);

        app(LiveTrackService::class)->poll($track);

        $this->assertEquals(500.0, $track->refresh()->state['distanceM']);
    }

    /** Ein Ausfall bei Garmin darf den gespeicherten Stand nicht zerstören. */
    public function test_a_failing_request_keeps_the_previous_state(): void
    {
        $track = $this->track(['state' => ['distanceM' => 20118.0, 'hr' => 148]]);

        Http::fake(['livetrack.garmin.com/*' => Http::response('', 503)]);

        app(LiveTrackService::class)->poll($track);
        $track->refresh();

        $this->assertNotNull($track->last_error);
        $this->assertEquals(20118.0, $track->state['distanceM']);
    }

    /** Distanz darf nie zurückspringen, auch wenn ein Ausreißer kommt. */
    public function test_distance_never_goes_backwards(): void
    {
        $track = $this->track(['state' => ['distanceM' => 20118.0, 'lastPointMs' => 1000]]);

        Http::fake([
            'livetrack.garmin.com/*' => Http::response([
                'trackPoints' => [[
                    'dateTime'         => '2026-08-08T12:00:00.000Z',
                    'fitnessPointData' => ['totalDistanceMeters' => 12.0],
                ]],
            ]),
        ]);

        app(LiveTrackService::class)->poll($track);

        $this->assertEquals(20118.0, $track->refresh()->state['distanceM']);
    }
}
