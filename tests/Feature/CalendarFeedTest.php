<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Kalender-Abo (Entscheidung vom 25.10.2026): geplante Einheiten und Rennen,
 * ganztägig, hinter einer geheimen Token-URL.
 */
class CalendarFeedTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-10-25';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY . ' 10:00:00');
        CarbonImmutable::setTestNow(self::TODAY . ' 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: TrainingPlan, 2: Event} */
    private function athlete(): array
    {
        $user  = User::factory()->onboarded()->create(['calendar_token' => Str::random(48)]);
        $event = Event::create([
            'user_id' => $user->id, 'name' => 'Sportcheck-Lauf', 'event_date' => '2026-11-29',
            'race_distance' => 'half_marathon', 'priority' => 'A',
            'target_time_hours' => 1, 'target_time_minutes' => 29,
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);

        return [$user, $plan, $event];
    }

    private function plannedSession(TrainingPlan $plan, array $attrs = []): TrainingSession
    {
        return TrainingSession::create($attrs + [
            'user_id' => $plan->user_id, 'training_plan_id' => $plan->id, 'event_id' => $plan->event_id,
            'planned_date' => '2026-10-27', 'type' => 'tempo_run', 'title' => 'Schwelle mit Kontrolle',
            'description' => 'Einlaufen, 3×10 min an der Schwelle, auslaufen.',
            'distance_km' => 10.5, 'duration_min' => 55, 'pace_target' => '4:15–4:20', 'zone' => 4,
            'intensity' => 'high', 'status' => 'planned',
        ]);
    }

    private function feed(User $user): string
    {
        $response = $this->get(route('calendar-feed.show', $user->calendar_token));
        $response->assertOk();
        $this->assertStringStartsWith('text/calendar', $response->headers->get('Content-Type'));

        return $response->getContent();
    }

    /** Lesbar machen: Faltungen auflösen, damit Prüfungen nicht an Zeilenumbrüchen scheitern. */
    private function unfold(string $ics): string
    {
        return str_replace("\r\n ", '', $ics);
    }

    public function test_the_feed_holds_planned_sessions_and_races_as_all_day_events(): void
    {
        [$user, $plan, $event] = $this->athlete();
        $session = $this->plannedSession($plan);

        $ics = $this->unfold($this->feed($user));

        $this->assertStringContainsString("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString("UID:session-{$session->id}@zone3", $ics);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261027', $ics);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20261028', $ics);
        $this->assertStringContainsString('SUMMARY:Schwelle mit Kontrolle', $ics);
        $this->assertStringContainsString('10\,5 km · 55 min · Pace 4:15–4:20 min/km · Zone 4', $ics);

        $this->assertStringContainsString("UID:race-{$event->id}@zone3", $ics);
        $this->assertStringContainsString('SUMMARY:🏁 Sportcheck-Lauf', $ics);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261129', $ics);
        $this->assertStringContainsString('Halbmarathon · Ziel 1:29 Std · A-Rennen', $ics);
    }

    public function test_rest_days_and_skipped_sessions_stay_out_completed_ones_are_ticked(): void
    {
        [$user, $plan] = $this->athlete();
        $this->plannedSession($plan, ['planned_date' => '2026-10-28', 'type' => 'rest', 'title' => 'Ruhetag', 'intensity' => 'rest']);
        $this->plannedSession($plan, ['planned_date' => '2026-10-29', 'title' => 'Ausgelassen', 'status' => 'skipped']);
        $this->plannedSession($plan, ['planned_date' => '2026-10-24', 'title' => 'Lockerer Lauf', 'status' => 'completed']);

        $ics = $this->unfold($this->feed($user));

        $this->assertStringNotContainsString('Ruhetag', $ics);
        $this->assertStringNotContainsString('Ausgelassen', $ics);
        $this->assertStringContainsString('SUMMARY:✓ Lockerer Lauf', $ics);
    }

    public function test_only_the_active_plan_counts(): void
    {
        [$user, $plan, $event] = $this->athlete();
        $old = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => false]);
        $this->plannedSession($old, ['title' => 'Alter Plan']);

        $this->assertStringNotContainsString('Alter Plan', $this->feed($user));
    }

    public function test_text_is_escaped_and_long_lines_are_folded_without_breaking_characters(): void
    {
        [$user, $plan] = $this->athlete();
        $this->plannedSession($plan, [
            'title'       => 'Tempo; locker, dann schnell',
            'description' => str_repeat('Überlänge mit Umlauten äöü ✓ ', 12) . "\nZweite Zeile",
        ]);

        $raw = $this->feed($user);

        foreach (explode("\r\n", $raw) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'), "Zeile zerbrochen: {$line}");
        }

        $ics = $this->unfold($raw);
        $this->assertStringContainsString('SUMMARY:Tempo\; locker\, dann schnell', $ics);
        $this->assertStringContainsString('\nZweite Zeile', $ics);
    }

    public function test_an_unknown_or_revoked_token_is_a_404(): void
    {
        [$user] = $this->athlete();
        $token  = $user->calendar_token;

        $this->get(route('calendar-feed.show', Str::random(48)))->assertNotFound();

        $this->actingAs($user)->delete(route('calendar-feed.destroy'))->assertRedirect();
        $this->assertNull($user->fresh()->calendar_token);
        $this->get("/calendar/{$token}.ics")->assertNotFound();
    }

    public function test_renewing_the_address_kills_the_old_one(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->post(route('calendar-feed.store'))->assertRedirect();
        $first = $user->fresh()->calendar_token;
        $this->assertSame(48, strlen($first));

        $this->actingAs($user)->post(route('calendar-feed.store'));
        $second = $user->fresh()->calendar_token;

        $this->assertNotSame($first, $second);
        $this->get("/calendar/{$first}.ics")->assertNotFound();
        $this->get("/calendar/{$second}.ics")->assertOk();
    }

    public function test_the_profile_offers_a_webcal_link(): void
    {
        [$user] = $this->athlete();

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('calendarFeed.url', route('calendar-feed.show', $user->calendar_token))
                ->where('calendarFeed.webcal', fn ($url) => str_starts_with($url, 'webcal://')));
    }

    public function test_the_token_is_never_exposed_with_the_user(): void
    {
        [$user] = $this->athlete();

        $this->assertArrayNotHasKey('calendar_token', $user->toArray());
    }
}
