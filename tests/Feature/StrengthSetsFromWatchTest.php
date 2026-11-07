<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\GarminConnection;
use App\Models\GarminWebhookEvent;
use App\Models\StrengthExercise;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Fit\FitDecoder;
use App\Services\Strength\Muscles;
use App\Services\Strength\StrengthSets;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Die Sätze eines Krafttrainings aus der FIT-Datei der Uhr (06.11.2026).
 *
 * Die FIT-Dateien hier sind nachgebaut (Nachricht `set`, FIT-Protokoll 2.0),
 * keine echten Athletendaten.
 */
class StrengthSetsFromWatchTest extends TestCase
{
    use RefreshDatabase;

    private const START = 1793948400; // 2026-11-06 07:00:00 UTC
    private const CALLBACK = 'https://apis.garmin.com/wellness-api/rest/activityFile?id=4711&token=t';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-06 18:00:00');

        $this->user = User::factory()->onboarded()->create();
        $this->user->forceFill(['activity_source' => Activity::SOURCE_GARMIN])->save();

        GarminConnection::create([
            'user_id'                 => $this->user->id,
            'garmin_user_id'          => 'g-user',
            'access_token'            => 'a',
            'refresh_token'           => 'r',
            'access_token_expires_at' => now()->addHours(20),
            'permissions'             => [GarminConnection::ACTIVITY],
            'ai_consent_at'           => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** [Kategorie-Nummer, Übungs-Nummer] aus Garmins Liste, damit kein Test Zahlen auswendig kennt. */
    private static function ids(string $category, ?string $name): array
    {
        foreach (StrengthSets::garminIds() as $num => $entry) {
            if ($entry['category'] === $category) {
                return [(int) $num, $name === null ? null : (int) array_search($name, $entry['exercises'], true)];
            }
        }

        throw new \RuntimeException("{$category} fehlt");
    }

    /**
     * Eine FIT-Datei nur mit `set`-Nachrichten.
     *
     * @param list<array{0: ?array, 1: int, 2: int, 3: ?float, 4?: int}> $sets [ids|null=unbekannt, Wdh., Dauer s, kg, Typ]
     */
    public static function fit(array $sets, int $start = self::START): string
    {
        $fitStart = $start - 631065600;
        $msgs = chr(0x40) . "\x00\x00" . pack('v', 225) . chr(8)
            . implode('', array_map(fn ($f) => chr($f[0]) . chr($f[1]) . chr($f[2]), [
                [254, 4, 0x86], [0, 4, 0x86], [3, 2, 0x84], [4, 2, 0x84], [5, 1, 0x02], [6, 4, 0x86], [7, 2, 0x84], [8, 2, 0x84],
            ]));

        $t = $fitStart;
        foreach ($sets as $s) {
            [$ids, $reps, $secs, $kg] = $s;
            $type = $s[4] ?? 1;
            [$cat, $sub] = $ids ?? [65534, 0xFFFF];
            $msgs .= "\x00" . pack('V', $t + $secs) . pack('V', $secs * 1000) . pack('v', $reps ?: 0xFFFF)
                . pack('v', $kg ? (int) round($kg * 16) : 0xFFFF) . chr($type) . pack('V', $t) . pack('v', $cat) . pack('v', $sub ?? 0xFFFF);
            $t += $secs + 60;
        }

        return chr(12) . chr(0x20) . pack('v', 2100) . pack('V', strlen($msgs)) . '.FIT' . $msgs . "\x00\x00";
    }

    private function workout(): string
    {
        $goblet = self::ids('SQUAT', 'GOBLET_SQUAT');
        $side   = self::ids('PLANK', 'SIDE_PLANK');
        $swing  = self::ids('HIP_RAISE', 'KETTLEBELL_SWING');

        return self::fit([
            [$goblet, 10, 40, 16.0],
            [null, 0, 60, null, 0], // Pause
            [$goblet, 10, 40, 16.0],
            [$goblet, 9, 38, 16.0],
            [$side, 0, 60, null],
            [$swing, 15, 30, 16.0],
            [null, 8, 26, null],
            [null, 0, 37, null],
        ]);
    }

    private function activity(): Activity
    {
        return Activity::create([
            'user_id' => $this->user->id, 'garmin_activity_id' => 4711, 'source' => Activity::SOURCE_GARMIN,
            'name' => 'Kraft', 'type' => 'WeightTraining', 'distance' => 0, 'moving_time' => 1200, 'elapsed_time' => 1200,
            'start_date' => Carbon::createFromTimestampUTC(self::START),
        ]);
    }

    private function plannedFor(Activity $activity): TrainingSession
    {
        return TrainingSession::create([
            'user_id' => $this->user->id, 'planned_date' => '2026-11-06', 'type' => 'strength', 'title' => 'Kraft für Läufer',
            'duration_min' => 35, 'intensity' => 'moderate', 'status' => 'completed', 'activity_id' => $activity->id,
            'exercises' => [
                ['key' => 'goblet_squat', 'name' => 'Goblet Squat', 'sets' => 3, 'reps' => '10', 'load' => '16 kg'],
                ['key' => 'side_plank', 'name' => 'Seitstütz', 'sets' => 2, 'reps' => '30 s je Seite'],
                ['key' => 'single_leg_landing', 'name' => 'Einbeinige Landung', 'sets' => 2, 'reps' => '6 je Bein'],
            ],
        ]);
    }

    private function push(array $item = []): void
    {
        $this->postJson('/garmin/webhook/activities', ['activityFiles' => [$item + [
            'userId' => 'g-user', 'summaryId' => '4711', 'activityId' => 4711, 'fileType' => 'FIT',
            'callbackURL' => self::CALLBACK, 'startTimeInSeconds' => self::START, 'manual' => false,
        ]]])->assertOk();
    }

    // ── FIT lesen ────────────────────────────────────────────────────────

    public function test_the_decoder_reads_sets_and_skips_rests(): void
    {
        $sets = StrengthSets::fromFit((new FitDecoder())->decode($this->workout())['sets'], Activity::SOURCE_GARMIN);

        $this->assertCount(7, $sets, 'die Pause fällt weg');
        $this->assertSame('SQUAT/GOBLET_SQUAT', $sets[0]['key']);
        $this->assertSame('Goblet Squat', $sets[0]['name']);
        $this->assertSame(10, $sets[0]['reps']);
        $this->assertSame(16.0, $sets[0]['weight_kg']);
        $this->assertSame(self::START, $sets[0]['start']);
        $this->assertSame(60, $sets[3]['duration_s']);
        $this->assertNull($sets[3]['reps']);
        $this->assertNull($sets[5]['key'], 'unbekannt bleibt unbekannt');
        $this->assertNull($sets[5]['name']);
    }

    // ── Abruf ────────────────────────────────────────────────────────────

    public function test_an_activity_file_push_stores_the_sets(): void
    {
        $activity = $this->activity();
        Http::fake(['apis.garmin.com/*' => Http::response($this->workout())]);

        $this->push();

        $this->assertCount(7, $activity->fresh()->strength_sets);
        Http::assertSent(fn (Request $r) => $r->url() === self::CALLBACK && $r->hasHeader('Authorization', 'Bearer a'));
        $event = GarminWebhookEvent::where('type', 'activityFiles')->first();
        $this->assertSame(GarminWebhookEvent::OUTCOME_STORED, $event->outcome);
        $this->assertStringNotContainsString('token=t', $event->payload, 'der Link trägt einen Schlüssel');
    }

    public function test_a_foreign_address_never_gets_the_token(): void
    {
        $this->activity();
        Http::fake();

        $this->push(['callbackURL' => 'https://evil.example/steal']);

        Http::assertNothingSent();
        $this->assertSame(GarminWebhookEvent::OUTCOME_CHECK_FAILED, GarminWebhookEvent::where('type', 'activityFiles')->value('outcome'));
    }

    public function test_a_run_is_not_downloaded(): void
    {
        $this->activity()->update(['type' => 'Run']);
        Http::fake();

        $this->push();

        Http::assertNothingSent();
    }

    // ── Anzeige und Vergleich ────────────────────────────────────────────

    public function test_sets_are_grouped_matched_and_compared_with_the_plan(): void
    {
        $activity = $this->activity();
        $activity->update(['strength_sets' => StrengthSets::fromFit((new FitDecoder())->decode($this->workout())['sets'], Activity::SOURCE_GARMIN)]);
        $session = $this->plannedFor($activity);

        $p = app(StrengthSets::class)->present($activity->fresh()->strength_sets, $session);

        $this->assertSame(['sets' => 7, 'reps' => 52], $p['totals']);
        $labels = array_column($p['exercises'], 'label');
        $this->assertSame([StrengthExercise::where('key', 'goblet_squat')->value('name'), StrengthExercise::where('key', 'side_plank')->value('name'), StrengthExercise::where('key', 'kettlebell_swing')->value('name'), 'Unbekannt'], $labels);
        $this->assertSame(3, $p['exercises'][0]['sets']);
        $this->assertSame('goblet_squat', $p['exercises'][0]['key']);

        $rows = collect($p['comparison']['rows'])->keyBy('key');
        $this->assertSame('done', $rows['goblet_squat']['status']);
        $this->assertSame('10 · 10 · 9, 16 kg', $rows['goblet_squat']['done']);
        $this->assertSame('partial', $rows['side_plank']['status']);
        $this->assertSame('missing', $rows['single_leg_landing']['status']);
        $this->assertSame(1, $p['comparison']['done_count']);
        $this->assertSame(3, $p['comparison']['planned_count']);
        $this->assertSame(['Kettlebell Swing'], array_column($p['comparison']['extra'], 'label'));
        $this->assertSame(2, $p['comparison']['unknown_sets'], 'unbekannte Sätze werden keiner Übung zugeschlagen');

        $this->assertSame(1.0, $p['muscles']['glutes'], 'Goblet Squat und Swing: das Gesäß trägt am meisten');
        $this->assertArrayHasKey('obliques', $p['muscles']);
    }

    public function test_an_exercise_outside_the_catalog_keeps_the_watch_name(): void
    {
        $activity = $this->activity();
        $burpee = self::ids('TOTAL_BODY', 'BURPEE');
        $activity->update(['strength_sets' => StrengthSets::fromFit((new FitDecoder())->decode(self::fit([[$burpee, 12, 30, null]]))['sets'], Activity::SOURCE_GARMIN)]);

        $p = app(StrengthSets::class)->present($activity->fresh()->strength_sets);

        $this->assertSame('Burpee', $p['exercises'][0]['label']);
        $this->assertNull($p['exercises'][0]['key']);
        $this->assertNull($p['comparison'], 'ohne geplante Einheit kein Vergleich');
    }

    /**
     * So schreibt COROS ein Krafttraining, bei dem auf der Uhr keine Übung
     * gewählt wurde (echte Datei vom 07.11.2026, nachgebaut): jeder Satz nur
     * mit der Kategorie SQUAT, ohne Übung — in COROS heißt das „Legs/Hips".
     * Das ist eine Körperregion, kein Kniebeugen.
     */
    public function test_a_category_without_exercise_is_not_an_exercise(): void
    {
        $activity = $this->activity();
        $legs = self::ids('SQUAT', null);
        $sets = StrengthSets::fromFit((new FitDecoder())->decode(self::fit([
            [$legs, 0, 31, null], [$legs, 6, 32, null], [$legs, 25, 247, null],
        ]))['sets'], Activity::SOURCE_COROS);
        $activity->update(['strength_sets' => $sets]);
        $session = $this->plannedFor($activity);

        $this->assertNull($sets[0]['name']);
        $this->assertSame('SQUAT', $sets[0]['group']);

        $p = app(StrengthSets::class)->present($activity->fresh()->strength_sets, $session);

        $this->assertCount(1, $p['exercises']);
        $this->assertSame('Übung nicht gewählt', $p['exercises'][0]['label']);
        $this->assertSame([], $p['comparison']['extra'], 'keine „zusätzliche Kniebeuge"');
        $this->assertSame(3, $p['comparison']['unknown_sets']);
        $this->assertArrayHasKey('quads', $p['muscles'], 'die Region färbt trotzdem die Beine');

        // Was vor dem Fix gespeichert wurde, trug die Kategorie als Namen.
        $old = array_map(fn ($s) => ['name' => 'Squat'] + $s, $sets);
        $this->assertSame('Übung nicht gewählt', app(StrengthSets::class)->present($old)['exercises'][0]['label']);
    }

    public function test_the_admin_mapping_decides_the_match(): void
    {
        $activity = $this->activity();
        $burpee = self::ids('TOTAL_BODY', 'BURPEE');
        $activity->update(['strength_sets' => StrengthSets::fromFit((new FitDecoder())->decode(self::fit([[$burpee, 12, 30, null]]))['sets'], Activity::SOURCE_GARMIN)]);

        // Der Admin ordnet die Übung neu zu: der Rückweg folgt sofort.
        StrengthExercise::where('key', 'squat')->first()->setAlias('garmin', 'TOTAL_BODY/BURPEE');

        $p = app(StrengthSets::class)->present($activity->fresh()->strength_sets);
        $this->assertSame('squat', $p['exercises'][0]['key']);
    }

    public function test_the_activity_page_shows_the_exercises(): void
    {
        $activity = $this->activity();
        $activity->update(['strength_sets' => StrengthSets::fromFit((new FitDecoder())->decode($this->workout())['sets'], Activity::SOURCE_GARMIN)]);
        $this->plannedFor($activity);

        $this->actingAs($this->user)->get("/activities/{$activity->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('strength.totals.sets', 7)
                ->where('strength.comparison.planned_count', 3)
                ->has('strength.exercises', 4)
                ->has('muscleLabels'));
    }

    public function test_every_catalog_exercise_has_muscles(): void
    {
        foreach (StrengthExercise::all() as $e) {
            $this->assertNotEmpty($e->muscles['primary'] ?? [], "{$e->key} hat keine Muskeln");
            foreach (array_merge($e->muscles['primary'], $e->muscles['secondary']) as $m) {
                $this->assertArrayHasKey($m, Muscles::LABELS);
            }
        }
    }
}
