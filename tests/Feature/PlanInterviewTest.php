<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\PlanInterview;
use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\PlanInterviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Das kurze Gespraech vor dem naechsten Block.
 *
 * Gewuenscht: „Vielleicht wäre ein kurzes Interview nicht schlecht damit sich
 * der Plan perfekt einstellen kann."
 *
 * Die Tests hier sichern die beiden Zusagen, an denen der Entwurf haengt:
 * das Interview fragt nur, was die Daten nicht hergeben — und die Antworten
 * landen dort, wo sie WIRKEN. Ein Interview, dessen Antworten nur in den
 * Prompt flossen, waere dieselbe doppelte Wahrheit, die bei den Renntagen
 * dreimal versagt hat: das Geruest ist bindend und liest keine Freitexte.
 */
class PlanInterviewTest extends TestCase
{
    use RefreshDatabase;

    private function athlete(array $grid = []): User
    {
        $user = User::factory()->onboarded()->create();

        RunnerProfile::create([
            'user_id'             => $user->id,
            'threshold_speed'     => 5.0,
            'weekly_availability' => $grid ?: $this->grid(['monday', 'wednesday', 'friday', 'sunday']),
        ]);

        return $user;
    }

    /** @param list<string> $open */
    private function grid(array $open, int $minutes = 60): array
    {
        $days = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $d) {
            $days[$d] = in_array($d, $open, true)
                ? ['available' => true,  'duration_min' => $minutes]
                : ['available' => false, 'duration_min' => 0];
        }

        return $days;
    }

    private function pastRace(User $user, string $name = 'Berlin Marathon'): Event
    {
        return Event::create([
            'user_id'             => $user->id,
            'name'                => $name,
            'event_date'          => now()->subDays(5),
            'race_distance'       => 'marathon',
            'priority'            => 'A',
            'target_time_hours'   => 3,
            'target_time_minutes' => 45,
        ]);
    }

    // ── Was die App schon weiss ──────────────────────────────────────────

    /**
     * Die Vorbelegung ist der Kern des Entwurfs: wer nach 132 Aktivitaeten
     * noch nach dem Wochenumfang fragt, baut eine zweite Wahrheit daneben.
     */
    public function test_the_interview_shows_what_the_app_already_knows(): void
    {
        $user = $this->athlete();
        $race = $this->pastRace($user);

        $this->actingAs($user)
            ->get(route('plan-interview.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Plan/Interview')
                ->where('prefill.lastBlock.name', 'Berlin Marathon')
                ->where('prefill.lastBlock.target_min', 225)
                ->where('prefill.availability.days', 4)
                ->where('prefill.availability.minutes', 60)
            );

        unset($race);
    }

    /** Die Ausfallquote rechtfertigt die Frage nach dem Warum. */
    public function test_the_skip_rate_is_computed_from_real_sessions(): void
    {
        $user = $this->athlete();

        foreach (range(1, 6) as $i) {
            TrainingSession::create([
                'user_id' => $user->id, 'planned_date' => now()->subDays($i),
                'type' => 'easy_run', 'title' => 'Lauf', 'status' => 'completed',
                'intensity' => 'low',
            ]);
        }

        foreach (range(7, 8) as $i) {
            TrainingSession::create([
                'user_id' => $user->id, 'planned_date' => now()->subDays($i),
                'type' => 'easy_run', 'title' => 'Lauf', 'status' => 'skipped',
                'intensity' => 'low',
            ]);
        }

        $prefill = app(PlanInterviewService::class)->prefill($user);

        $this->assertSame(6, $prefill['adherence']['completed']);
        $this->assertSame(2, $prefill['adherence']['skipped']);
        $this->assertSame(25, $prefill['adherence']['skip_pct']);
    }

    /** Wer zum ersten Mal plant, bekommt keinen Rueckblick vorgesetzt. */
    public function test_without_a_past_race_there_is_nothing_to_look_back_on(): void
    {
        $prefill = app(PlanInterviewService::class)->prefill($this->athlete());

        $this->assertNull($prefill['lastBlock']);
    }

    // ── Was die Antworten bewirken ───────────────────────────────────────

    /**
     * Die wichtigste Zusicherung: das Wochenraster ist der staerkste Hebel
     * aufs Geruest, und das Interview setzt es wirklich.
     */
    public function test_the_answers_rewrite_the_weekly_grid(): void
    {
        $user = $this->athlete($this->grid(['monday', 'wednesday', 'friday', 'sunday']));

        $this->actingAs($user)->post(route('plan-interview.store'), [
            'days_per_week'   => 3,
            'minutes_per_day' => 75,
        ])->assertRedirect();

        $grid = $user->runnerProfile->fresh()->weekly_availability;
        $open = collect($grid)->filter(fn ($d) => $d['available']);

        $this->assertCount(3, $open, 'Aus vier Tagen werden drei');
        $this->assertGreaterThanOrEqual(75, $open->min('duration_min'));
    }

    /**
     * Von acht vergangenen Rennen hatte keines ein Ergebnis — und
     * `pastPlanResults()` filtert auf `overall_rating`, lief also seit jeher
     * leer. Das Interview fuellt den Mechanismus, der schon da war.
     */
    public function test_the_block_review_fills_the_race_record_that_stayed_empty(): void
    {
        $user = $this->athlete();
        $race = $this->pastRace($user);

        $plan = TrainingPlan::create([
            'user_id' => $user->id, 'event_id' => $race->id, 'sessions' => [],
        ]);

        $this->assertNull($plan->overall_rating, 'Vorher leer — das ist der Ausgangspunkt');

        $this->actingAs($user)->post(route('plan-interview.store'), [
            'reviewed_event_id' => $race->id,
            'actual_minutes'    => 218,
            'block_rating'      => 4,
            'block_note'        => 'Die langen Läufe haben getragen.',
        ])->assertRedirect();

        $plan->refresh();

        $this->assertSame(4, $plan->overall_rating);
        $this->assertSame(3, $plan->actual_time_hours);
        $this->assertSame(38, $plan->actual_time_minutes, '218 min sind 3:38');
        $this->assertSame('Die langen Läufe haben getragen.', $plan->result_notes);
    }

    /**
     * Wer aus Zeitmangel ausfaellt, bekommt nicht denselben Umfang noch
     * einmal vorgelegt. Sonst bleibt die Ausfallquote, wo sie war.
     */
    public function test_the_skip_reason_lowers_the_volume_ceiling(): void
    {
        $user = $this->athlete();

        $interview = PlanInterview::create([
            'user_id'      => $user->id,
            'skip_reason'  => 'time',
            'completed_at' => now(),
        ]);

        $this->assertEqualsWithDelta(0.85, $interview->volumeFactor(), 0.001);

        $forPlan = app(PlanInterviewService::class)->forPlan($user);

        $this->assertSame('time', $forPlan['skip_reason']);
        $this->assertEqualsWithDelta(0.85, $forPlan['volume_factor'], 0.001);
    }

    /** „Kaum etwas ausgefallen" aendert am Umfang nichts. */
    public function test_a_clean_block_leaves_the_volume_alone(): void
    {
        $interview = new PlanInterview(['skip_reason' => 'none']);

        $this->assertEqualsWithDelta(1.0, $interview->volumeFactor(), 0.001);
    }

    /**
     * Ein Interview von vor einem halben Jahr beschreibt einen anderen
     * Athleten — danach zaehlen wieder die Daten allein.
     */
    public function test_an_old_interview_stops_counting(): void
    {
        $user = $this->athlete();

        PlanInterview::create([
            'user_id'      => $user->id,
            'skip_reason'  => 'time',
            'completed_at' => now()->subWeeks(12),
        ]);

        $this->assertNull(app(PlanInterviewService::class)->forPlan($user));
    }

    /** Freitexte gehen an den Coach — und nur die. */
    public function test_free_text_reaches_the_coach_notes(): void
    {
        $user = $this->athlete();

        $this->actingAs($user)->post(route('plan-interview.store'), [
            'skip_reason' => 'time',
            'free_note'   => 'Ich will unter 20 Minuten auf 5 km.',
        ])->assertRedirect();

        $notes = $user->runnerProfile->fresh()->coach_notes;

        $this->assertStringContainsString('unter 20 Minuten', $notes);
        $this->assertStringContainsString('Zeit / Beruf', $notes, 'Der Ausfallgrund wird mitgegeben');
    }

    // ── Zugriff und Robustheit ───────────────────────────────────────────

    /** Ein fremdes Event laesst sich nicht unterschieben. */
    public function test_someone_elses_event_is_refused(): void
    {
        $mine   = $this->athlete();
        $theirs = $this->pastRace($this->athlete(), 'Fremdes Rennen');

        $this->actingAs($mine)
            ->post(route('plan-interview.store'), ['reviewed_event_id' => $theirs->id])
            ->assertForbidden();
    }

    /**
     * Ein halb beantwortetes Interview ist mehr wert als ein abgebrochenes —
     * deshalb ist jedes Feld optional.
     */
    public function test_a_half_answered_interview_is_accepted(): void
    {
        $user = $this->athlete();

        $this->actingAs($user)
            ->post(route('plan-interview.store'), ['focus' => 'base'])
            ->assertRedirect();

        $this->assertSame('base', PlanInterview::where('user_id', $user->id)->first()->focus);
    }

    /** Zweimal anwenden darf nicht zweimal wirken. */
    public function test_applying_twice_changes_nothing_the_second_time(): void
    {
        $user = $this->athlete();

        $interview = PlanInterview::create([
            'user_id'      => $user->id,
            'free_note'    => 'Einmal notieren, nicht zweimal.',
            'completed_at' => now(),
        ]);

        $service = app(PlanInterviewService::class);

        $service->apply($interview);
        $after = $user->runnerProfile->fresh()->coach_notes;

        $this->assertSame([], $service->apply($interview->fresh()), 'Der zweite Lauf tut nichts');
        $this->assertSame($after, $user->runnerProfile->fresh()->coach_notes);
    }

    public function test_guests_cannot_see_the_interview(): void
    {
        $this->get(route('plan-interview.show'))->assertRedirect(route('login'));
    }
}
