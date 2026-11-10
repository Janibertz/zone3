<?php

namespace Tests\Feature;

use App\Models\RunnerProfile;
use App\Models\User;
use App\Models\WellbeingEntry;
use App\Services\PaceFormat;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Die Zahlen des Tagesvorschlags kommen aus dem Katalog, nicht vom Modell.
 *
 * Gemeldet am 10.11.2026: „Lockerer Kältestart, 3 km in 30 min,
 * 9:00–10:00 /km" — für einen Läufer mit Ziel HM unter 1:30. Das Modell
 * kannte keine Trainingstempi und schrieb Strecke und Dauer unabhängig.
 */
class TodayRecommendationPaceTest extends TestCase
{
    use RefreshDatabase;

    private const WALKING = '{"type":"easy_run","title":"Lockerer Kältestart","description":"Ruhig rein.","distance_km":3,"duration_min":30,"pace_target":"9:00-10:00","zone":1,"intensity":"low"}';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 07:30:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function athlete(): User
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);
        // Schwelle 4:15 — locker liegt um 5:10–5:50, nie bei 9:00.
        RunnerProfile::create(['user_id' => $user->id, 'threshold_speed' => 4.25]);
        WellbeingEntry::create(['user_id' => $user->id, 'date' => '2026-11-10', 'energy_level' => 6,
            'sleep_quality' => 6, 'muscle_soreness' => 3, 'stress_level' => 4, 'mood' => 6]);

        return $user;
    }

    private function fakeModel(string $content): void
    {
        config(['services.openai.api_key' => 'test-key']);
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
            'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
        ], 200)]);
    }

    /** Sekunden je km der langsamen Grenze einer Pace wie "5:12–5:48". */
    private function slowestSec(string $pace): int
    {
        preg_match_all('/(\d+):(\d{2})/', $pace, $m, PREG_SET_ORDER);
        $last = end($m);

        return (int) $last[1] * 60 + (int) $last[2];
    }

    public function test_the_pace_comes_from_the_vdot_not_the_model(): void
    {
        $user = $this->athlete();
        $this->fakeModel(self::WALKING);

        $rec = $this->actingAs($user)->getJson('/api/ai/recommendation/today')
            ->assertOk()->json('recommendation');

        $this->assertNotSame('9:00-10:00', $rec['pace_target']);
        $this->assertLessThan(7 * 60, $this->slowestSec($rec['pace_target']));
        $this->assertNotEmpty($rec['steps']);
    }

    public function test_distance_and_duration_agree(): void
    {
        $user = $this->athlete();
        $this->fakeModel(self::WALKING);

        $rec = $this->actingAs($user)->getJson('/api/ai/recommendation/today')->json('recommendation');

        $this->assertSame(30, $rec['duration_min']);
        // 30 min locker bei rund 5:30 sind gut 5 km, nicht 3.
        $this->assertGreaterThan(4.5, $rec['distance_km']);
        $this->assertLessThan(6.5, $rec['distance_km']);
    }

    public function test_the_prompt_names_the_training_paces(): void
    {
        $user = $this->athlete();
        $this->fakeModel(self::WALKING);

        $this->actingAs($user)->getJson('/api/ai/recommendation/today');

        Http::assertSent(fn (Request $r) => str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'Trainingstempi (aus dem VDOT, verbindlich): locker'));
    }

    public function test_a_cached_suggestion_is_corrected_without_a_model_call(): void
    {
        $user    = $this->athlete();
        $profile = $user->runnerProfile;
        $profile->forceFill([
            'today_recommendation'        => self::WALKING,
            'recommendation_date'         => '2026-11-10',
            'recommendation_wellbeing_id' => $user->wellbeingEntries()->first()->id,
        ])->save();
        Http::fake();

        $rec = $this->actingAs($user)->getJson('/api/ai/recommendation/today')->json('recommendation');

        Http::assertNothingSent();
        $this->assertLessThan(7 * 60, $this->slowestSec($rec['pace_target']));
    }

    public function test_a_rest_day_stays_a_rest_day(): void
    {
        $user = $this->athlete();
        $this->fakeModel('{"type":"rest","title":"Ruhetag","description":"Pause.","distance_km":0,"duration_min":0,"pace_target":null,"zone":null,"intensity":"low"}');

        $rec = $this->actingAs($user)->getJson('/api/ai/recommendation/today')->json('recommendation');

        $this->assertSame('rest', $rec['type']);
        $this->assertArrayNotHasKey('steps', $rec);
    }

    private function weekly(array $tuesday): array
    {
        $off = ['available' => false, 'duration_min' => 0];

        return ['monday' => $off, 'tuesday' => $tuesday, 'wednesday' => $off, 'thursday' => $off,
            'friday' => $off, 'saturday' => $off, 'sunday' => $off];
    }

    public function test_a_day_without_time_is_a_rest_day_without_the_model(): void
    {
        $user = $this->athlete();
        $user->runnerProfile->update(['weekly_availability' => $this->weekly(['available' => false, 'duration_min' => 0])]);
        Http::fake();

        $rec = $this->actingAs($user)->getJson('/api/ai/recommendation/today')->json('recommendation');

        Http::assertNothingSent();
        $this->assertSame('rest', $rec['type']);
        $this->assertSame('Heute frei', $rec['title']);
    }

    public function test_the_time_window_caps_the_duration(): void
    {
        $user = $this->athlete();
        $user->runnerProfile->update(['weekly_availability' => $this->weekly(['available' => true, 'duration_min' => 25])]);
        $this->fakeModel('{"type":"easy_run","title":"Locker","description":"x","distance_km":10,"duration_min":55,"pace_target":"5:30","zone":2,"intensity":"low"}');

        $rec = $this->actingAs($user)->getJson('/api/ai/recommendation/today')->json('recommendation');

        $this->assertLessThanOrEqual(25, $rec['duration_min']);
        $this->assertLessThan(5.5, $rec['distance_km']);
        Http::assertSent(fn (Request $r) => str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'maximal 25 Minuten'));
    }

    public function test_the_fixed_appointment_is_suggested_without_a_structure(): void
    {
        $user = $this->athlete();
        $user->runnerProfile->update(['weekly_availability' => $this->weekly([
            'available' => true, 'duration_min' => 60, 'fixed' => ['type' => 'interval', 'label' => 'Laufclub'],
        ])]);
        $this->fakeModel('{"type":"interval","title":"Laufclub","description":"x","distance_km":10,"duration_min":60,"pace_target":"4:00","zone":4,"intensity":"high"}');

        $rec = $this->actingAs($user)->getJson('/api/ai/recommendation/today')->json('recommendation');

        $this->assertSame('Laufclub', $rec['title']);
        $this->assertNull($rec['steps']);
        $this->assertNull($rec['pace_target']);
        Http::assertSent(fn (Request $r) => str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'fester Termin an: Laufclub'));
    }
}
