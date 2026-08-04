<?php

namespace Tests\Feature;

use App\Models\LiveTrack;
use App\Models\User;
use App\Services\LiveTrackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
            'crew_key'          => 'crewschluessel12345',
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

    // ── Crew ─────────────────────────────────────────────────────────────

    /** Der Kern der Sache: der oeffentliche Link darf NICHT steuern duerfen. */
    public function test_public_link_alone_cannot_control_the_race(): void
    {
        $track = $this->track();

        $this->postJson(route('live.crew', $track->slug), [
            'crew'            => 'admin',          // das naive "?admin=true"
            'stopped_at_yard' => 3,
        ])->assertForbidden();

        $this->postJson(route('live.crew', $track->slug), [
            'crew'            => 'geratenerschluessel',
            'stopped_at_yard' => 3,
        ])->assertForbidden();

        $this->assertNull($track->refresh()->stopped_at_yard);
    }

    public function test_crew_key_may_set_the_final_yard_count(): void
    {
        $track = $this->track();

        $this->postJson(route('live.crew', $track->slug), [
            'crew'            => 'crewschluessel12345',
            'stopped_at_yard' => 17,
        ])->assertOk()->assertJsonPath('stoppedAtYard', 17);

        $this->assertSame(17, $track->refresh()->stopped_at_yard);
    }

    public function test_crew_may_post_and_delete_ticker_notes(): void
    {
        $track = $this->track();

        $this->postJson(route('live.crew', $track->slug), [
            'crew' => 'crewschluessel12345',
            'note' => 'Runde 12 geschafft',
        ])->assertOk()->assertJsonPath('notes.0.text', 'Runde 12 geschafft');

        // Neueste Meldung steht vorn
        $this->postJson(route('live.crew', $track->slug), [
            'crew' => 'crewschluessel12345',
            'note' => 'Pause im Zelt',
        ])->assertOk()
          ->assertJsonPath('notes.0.text', 'Pause im Zelt')
          ->assertJsonPath('notes.1.text', 'Runde 12 geschafft');

        $id = $track->refresh()->notes[0]['id'];

        $this->postJson(route('live.crew', $track->slug), [
            'crew'        => 'crewschluessel12345',
            'delete_note' => $id,
        ])->assertOk()->assertJsonCount(1, 'notes');
    }

    /** Der Crew-Schluessel darf nicht auf der oeffentlichen Seite auftauchen. */
    public function test_crew_key_is_not_leaked_to_plain_visitors(): void
    {
        $track = $this->track();

        $this->get(route('live.show', $track->slug))
            ->assertOk()
            ->assertDontSee('crewschluessel12345');

        $this->getJson(route('live.data', $track->slug))
            ->assertOk()
            ->assertDontSee('crewschluessel12345');
    }

    /** Mit richtigem Schluessel schaltet die Seite die Steuerleiste frei. */
    public function test_crew_link_unlocks_the_control_panel(): void
    {
        $track = $this->track();

        $this->get(route('live.show', $track->slug) . '?crew=crewschluessel12345')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('isCrew', true));

        $this->get(route('live.show', $track->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('isCrew', false));
    }

    /** Ein neuer Eintrag bekommt seine Schluessel automatisch. */
    public function test_a_new_track_always_gets_slug_and_crew_key(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        $track = LiveTrack::create([
            'user_id'   => $user->id,
            'title'     => 'Ohne Schluessel angelegt',
            'starts_at' => now(),
        ]);

        $this->assertNotEmpty($track->slug);
        $this->assertNotEmpty($track->crew_key);
    }

    /** Altbestand ohne Schluessel darf keine kaputte Crew-URL erzeugen. */
    public function test_manage_page_backfills_a_missing_crew_key(): void
    {
        $track = $this->track();
        $track->forceFill(['crew_key' => null])->save();

        $this->actingAs($track->user)
            ->get(route('live.manage'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'track.crewUrl',
                fn ($url) => is_string($url) && ! str_ends_with($url, '?crew=')
            ));

        $this->assertNotEmpty($track->refresh()->crew_key);
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

    /** Ohne eingeschaltete Karte darf der Token auch nicht ueber mapUrl rausgehen. */
    public function test_map_is_only_embedded_when_switched_on(): void
    {
        $track = $this->track();

        $this->getJson(route('live.data', $track->slug))
            ->assertOk()
            ->assertJsonPath('mapUrl', null)
            ->assertDontSee('SECRET-LIVETRACK-TOKEN');

        $track->update(['embed_map' => true]);

        $this->getJson(route('live.data', $track->slug))
            ->assertOk()
            ->assertSee('SECRET-LIVETRACK-TOKEN');
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

    // ── Garmin-Link durch die Crew ───────────────────────────────────────

    /**
     * Den LiveTrack-Link gibt es erst, wenn die Uhr die Aktivitaet startet.
     * Der Laeufer ist dann unterwegs — also muss ihn die Crew nachtragen
     * koennen.
     */
    public function test_crew_may_add_the_livetrack_link(): void
    {
        $track = $this->track(['garmin_session_id' => null, 'garmin_token' => null, 'embed_map' => false]);

        $this->postJson(route('live.crew', $track->slug), [
            'crew'          => 'crewschluessel12345',
            'livetrack_url' => 'https://livetrack.garmin.com/session/0c6dc0e7-880b-8070-878c-6257c4789700/token/91AA529345445E64F8EFE73A8D58AAEC',
            'embed_map'     => true,
        ])->assertOk()->assertJsonPath('hasLiveTrack', true);

        $track->refresh();
        $this->assertSame('0c6dc0e7-880b-8070-878c-6257c4789700', $track->garmin_session_id);
        $this->assertSame('91AA529345445E64F8EFE73A8D58AAEC', $track->garmin_token);
    }

    public function test_crew_may_remove_the_livetrack_link(): void
    {
        $track = $this->track(['embed_map' => true]);

        $this->postJson(route('live.crew', $track->slug), [
            'crew'          => 'crewschluessel12345',
            'livetrack_url' => '',
            'embed_map'     => false,
        ])->assertOk()
          ->assertJsonPath('hasLiveTrack', false)
          ->assertJsonPath('mapUrl', null);

        $this->assertNull($track->refresh()->garmin_session_id);
    }

    public function test_a_nonsense_link_from_the_crew_is_rejected(): void
    {
        $track = $this->track();

        $this->postJson(route('live.crew', $track->slug), [
            'crew'          => 'crewschluessel12345',
            'livetrack_url' => 'https://example.com/irgendwas',
        ])->assertStatus(422);

        // Der vorhandene Link bleibt unangetastet.
        $this->assertSame('SECRET-LIVETRACK-TOKEN', $track->refresh()->garmin_token);
    }

    /** Der oeffentliche Link allein darf die Karte nicht umschalten. */
    public function test_visitors_cannot_set_the_livetrack_link(): void
    {
        $track = $this->track(['garmin_session_id' => null, 'garmin_token' => null]);

        $this->postJson(route('live.crew', $track->slug), [
            'crew'          => 'falsch',
            'livetrack_url' => 'https://livetrack.garmin.com/session/aaaaaaaa-bbbb/token/ABC123',
        ])->assertForbidden();

        $this->assertNull($track->refresh()->garmin_session_id);
    }

    // ── Bilder im Ticker ─────────────────────────────────────────────────

    public function test_crew_may_attach_an_image_to_a_note(): void
    {
        Storage::fake('public');
        $track = $this->track();

        $this->post(route('live.crew', $track->slug), [
            'crew'  => 'crewschluessel12345',
            'note'  => 'Wechselzone',
            'image' => UploadedFile::fake()->image('zelt.jpg'),
        ])->assertOk()
          ->assertJsonPath('notes.0.text', 'Wechselzone');

        $stored = $track->refresh()->notes[0]['image'];
        $this->assertNotNull($stored);
        Storage::disk('public')->assertExists($stored);
    }

    /** Ein Bild allein ist auch eine Meldung. */
    public function test_an_image_without_text_is_a_valid_note(): void
    {
        Storage::fake('public');
        $track = $this->track();

        $this->post(route('live.crew', $track->slug), [
            'crew'  => 'crewschluessel12345',
            'image' => UploadedFile::fake()->image('sonnenaufgang.jpg'),
        ])->assertOk()->assertJsonCount(1, 'notes');

        $this->assertSame('', $track->refresh()->notes[0]['text']);
    }

    /** Wird die Meldung geloescht, darf ihr Bild nicht liegen bleiben. */
    public function test_deleting_a_note_removes_its_image(): void
    {
        Storage::fake('public');
        $track = $this->track();

        $this->post(route('live.crew', $track->slug), [
            'crew'  => 'crewschluessel12345',
            'image' => UploadedFile::fake()->image('zelt.jpg'),
        ])->assertOk();

        $note = $track->refresh()->notes[0];
        Storage::disk('public')->assertExists($note['image']);

        $this->postJson(route('live.crew', $track->slug), [
            'crew'        => 'crewschluessel12345',
            'delete_note' => $note['id'],
        ])->assertOk()->assertJsonCount(0, 'notes');

        Storage::disk('public')->assertMissing($note['image']);
    }

    /** Die Seite liefert eine fertige Adresse, keinen Ablagepfad. */
    public function test_note_images_are_served_as_urls(): void
    {
        Storage::fake('public');
        $track = $this->track();

        $response = $this->post(route('live.crew', $track->slug), [
            'crew'  => 'crewschluessel12345',
            'image' => UploadedFile::fake()->image('zelt.jpg'),
        ])->assertOk();

        $url = $response->json('notes.0.image');
        $this->assertStringStartsWith('/storage/', $url);
    }

    /** Ein Besucher ohne Schluessel darf nichts hochladen. */
    public function test_visitors_cannot_upload_images(): void
    {
        Storage::fake('public');
        $track = $this->track();

        $this->post(route('live.crew', $track->slug), [
            'crew'  => 'falsch',
            'image' => UploadedFile::fake()->image('zelt.jpg'),
        ])->assertForbidden();

        $this->assertEmpty($track->refresh()->notes ?? []);
    }
}
