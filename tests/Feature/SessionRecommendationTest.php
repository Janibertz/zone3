<?php

namespace Tests\Feature;

use App\Jobs\RecommendForWellbeingJob;
use App\Models\Event;
use App\Models\SessionRecommendation;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WellbeingEntry;
use App\Services\AI\SessionContentService;
use App\Services\GarminHealthSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Der Coach schlägt vor, der Athlet entscheidet.
 *
 * Gemeldet: „Der Coach passt die Trainingseinheit automatisch an das
 * Wellbeing an. Ich finde der Coach sollte eher eine Empfehlung auf dem
 * Dashboard zu der heutigen Einheit geben und der Athlet darf selber
 * entscheiden ob die Empfehlung des Coaches angenommen oder abgelehnt
 * werden soll."
 *
 * Vorher schrieb `AdjustPlanForWellbeingJob` die heutige Einheit direkt um —
 * Typ, Titel, Distanz, Dauer, Pace, Zone, Intensität. Wer sich auf ein
 * Schwellentraining eingestellt hatte und zwanzig lockere Minuten vorfand,
 * erlebte seinen Plan als etwas, das ihm zustösst.
 */
class SessionRecommendationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: TrainingSession} */
    private function athleteWithSession(): array
    {
        $user = User::factory()->onboarded()->create();

        $event = Event::create([
            'user_id' => $user->id, 'name' => 'Zielrennen',
            'event_date' => now()->addDays(40), 'race_distance' => 'marathon',
            'priority' => 'A', 'target_time_hours' => 3, 'target_time_minutes' => 30,
        ]);

        $plan = TrainingPlan::create([
            'user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [],
        ]);
        // `is_active` ist nicht fillable — ohne forceFill findet der Job
        // keinen aktiven Plan und legt gar nichts an.
        $plan->forceFill(['is_active' => true])->save();

        $session = TrainingSession::create([
            'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $event->id,
            'planned_date' => now()->toDateString(),
            'type' => 'interval', 'title' => 'Schwelle mit Kontrolle',
            'description' => 'Harte Einheit', 'distance_km' => 12, 'duration_min' => 60,
            'pace_target' => '4:30', 'zone' => 4, 'intensity' => 'high',
            'status' => 'planned',
        ]);

        return [$user, $session];
    }

    private function recommendation(User $user, TrainingSession $session, array $after = []): SessionRecommendation
    {
        return SessionRecommendation::create([
            'user_id'             => $user->id,
            'training_session_id' => $session->id,
            'source'              => SessionRecommendation::SOURCE_WELLBEING,
            'reason'              => 'HRV 25 % unter der Grundlinie.',
            'before'              => ['type' => 'interval', 'title' => 'Schwelle mit Kontrolle', 'distance_km' => 12, 'intensity' => 'high'],
            'after'               => array_merge([
                'type' => 'easy_run', 'title' => 'Locker statt Intervalle',
                'description' => 'Heute ruhig.', 'distance_km' => 8,
                'duration_min' => 45, 'pace_target' => '5:30', 'zone' => 2, 'intensity' => 'low',
            ], $after),
        ]);
    }

    // ── Der Plan bleibt, bis der Athlet zustimmt ─────────────────────────

    public function test_a_pending_recommendation_does_not_touch_the_plan(): void
    {
        [$user, $session] = $this->athleteWithSession();
        $this->recommendation($user, $session);

        $session->refresh();

        $this->assertSame('interval', $session->type);
        $this->assertSame('Schwelle mit Kontrolle', $session->title);
        $this->assertNull($session->pinned_at);
    }

    public function test_accepting_writes_it_into_the_session(): void
    {
        [$user, $session] = $this->athleteWithSession();
        $recommendation = $this->recommendation($user, $session);

        $this->actingAs($user)
            ->post(route('recommendations.accept', $recommendation->id))
            ->assertRedirect();

        $session->refresh();

        $this->assertSame('easy_run', $session->type);
        $this->assertSame('Locker statt Intervalle', $session->title);
        $this->assertSame(8.0, (float) $session->distance_km);
        $this->assertSame('low', $session->intensity);
        $this->assertSame('accepted', $recommendation->fresh()->status);
    }

    /**
     * Angenommen heisst: die Entscheidung des Athleten. Ohne `pinned_at`
     * würde die nächste Neuberechnung sie still wieder wegwerfen — genau
     * der Mechanismus, den der Coach-Chat schon nutzt.
     */
    public function test_an_accepted_session_survives_a_regeneration(): void
    {
        [$user, $session] = $this->athleteWithSession();
        $recommendation = $this->recommendation($user, $session);

        $this->actingAs($user)->post(route('recommendations.accept', $recommendation->id));

        $this->assertNotNull($session->fresh()->pinned_at);
    }

    /**
     * Steps und Verpflegungstipps beschrieben die alte Einheit — sie neben
     * einer anderen stehen zu lassen, wäre der Fehler, den wir bei
     * Beschreibung und Trainingsstruktur schon einmal hatten.
     */
    public function test_accepting_clears_the_old_steps(): void
    {
        [$user, $session] = $this->athleteWithSession();
        $session->update([
            'steps'          => [['duration_min' => 10, 'label' => 'Einlaufen']],
            'nutrition_tips' => ['before' => [['icon' => '🍝', 'text' => 'Pasta']]],
        ]);

        $recommendation = $this->recommendation($user, $session);
        $this->actingAs($user)->post(route('recommendations.accept', $recommendation->id));

        $session->refresh();

        $this->assertNull($session->steps);
        $this->assertNull($session->nutrition_tips);
    }

    public function test_rejecting_leaves_everything_alone(): void
    {
        [$user, $session] = $this->athleteWithSession();
        $recommendation = $this->recommendation($user, $session);

        $this->actingAs($user)
            ->post(route('recommendations.reject', $recommendation->id))
            ->assertRedirect();

        $session->refresh();

        $this->assertSame('interval', $session->type);
        $this->assertNull($session->pinned_at);
        $this->assertSame('rejected', $recommendation->fresh()->status);
    }

    public function test_a_decision_cannot_be_taken_twice(): void
    {
        [$user, $session] = $this->athleteWithSession();
        $recommendation = $this->recommendation($user, $session);

        $this->actingAs($user)->post(route('recommendations.reject', $recommendation->id));
        $this->actingAs($user)->post(route('recommendations.accept', $recommendation->id));

        $this->assertSame('interval', $session->fresh()->type, 'Abgelehnt bleibt abgelehnt');
        $this->assertSame('rejected', $recommendation->fresh()->status);
    }

    public function test_a_stranger_cannot_decide(): void
    {
        [$user, $session] = $this->athleteWithSession();
        $recommendation = $this->recommendation($user, $session);

        $this->actingAs(User::factory()->onboarded()->create())
            ->post(route('recommendations.accept', $recommendation->id))
            ->assertForbidden();

        $this->assertSame('interval', $session->fresh()->type);
    }

    // ── Was der Job daraus macht ─────────────────────────────────────────

    private function coachAnswers(array $answer): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($answer)], 'finish_reason' => 'stop']],
        ])]);
    }

    public function test_the_job_creates_a_recommendation_instead_of_changing_the_plan(): void
    {
        [$user, $session] = $this->athleteWithSession();

        $wellbeing = WellbeingEntry::create([
            'user_id' => $user->id, 'date' => now()->toDateString(),
            'energy_level' => 2, 'mood' => 4, 'sleep_quality' => 3,
            'muscle_soreness' => 7, 'stress_level' => 8,
        ]);

        $this->coachAnswers([
            'reason' => 'Energie 2/10 und Muskelkater 7/10.',
            'type' => 'easy_run', 'title' => 'Locker statt Intervalle',
            'description' => 'Heute ruhig.', 'distance_km' => 8, 'duration_min' => 45,
            'pace_target' => '5:30', 'zone' => 2, 'intensity' => 'low',
        ]);

        (new RecommendForWellbeingJob($user->id, $wellbeing->id))
            ->handle(app(SessionContentService::class), app(GarminHealthSummary::class));

        $this->assertSame('interval', $session->fresh()->type, 'Der Plan bleibt unberührt');

        $recommendation = SessionRecommendation::where('training_session_id', $session->id)->first();

        $this->assertNotNull($recommendation);
        $this->assertSame('pending', $recommendation->status);
        $this->assertStringContainsString('Energie 2/10', $recommendation->reason);
    }

    /**
     * Schlägt der Coach nichts anderes vor, gibt es nichts zu entscheiden.
     * Eine Karte mit „alles bleibt" wäre nur Lärm — und der Athlet lernt,
     * sie wegzuklicken, ohne zu lesen.
     */
    public function test_no_card_when_nothing_really_changes(): void
    {
        [$user, $session] = $this->athleteWithSession();

        $wellbeing = WellbeingEntry::create([
            'user_id' => $user->id, 'date' => now()->toDateString(),
            'energy_level' => 8, 'mood' => 8, 'sleep_quality' => 8,
            'muscle_soreness' => 2, 'stress_level' => 2,
        ]);

        // Dieselbe Einheit, nur anders formuliert.
        $this->coachAnswers([
            'reason' => 'Alles im grünen Bereich.',
            'type' => 'interval', 'title' => 'Schwelle mit Kontrolle',
            'description' => 'Andere Worte, gleiches Training.',
            'distance_km' => 12, 'duration_min' => 60,
            'pace_target' => '4:30', 'zone' => 4, 'intensity' => 'high',
        ]);

        (new RecommendForWellbeingJob($user->id, $wellbeing->id))
            ->handle(app(SessionContentService::class), app(GarminHealthSummary::class));

        $this->assertSame(0, SessionRecommendation::count());
    }

    /**
     * Ein zweiter Check-in am selben Tag ersetzt den alten Vorschlag — zwei
     * offene Karten nebeneinander wären nicht zu entscheiden.
     */
    public function test_a_newer_recommendation_supersedes_the_open_one(): void
    {
        [$user, $session] = $this->athleteWithSession();
        $old = $this->recommendation($user, $session);

        $wellbeing = WellbeingEntry::create([
            'user_id' => $user->id, 'date' => now()->toDateString(),
            'energy_level' => 1, 'mood' => 2, 'sleep_quality' => 2,
            'muscle_soreness' => 9, 'stress_level' => 9,
        ]);

        $this->coachAnswers([
            'reason' => 'Jetzt noch schlechter.',
            'type' => 'rest', 'title' => 'Ruhetag', 'description' => 'Nichts heute.',
            'distance_km' => 0, 'duration_min' => 0, 'pace_target' => null,
            'zone' => null, 'intensity' => 'rest',
        ]);

        (new RecommendForWellbeingJob($user->id, $wellbeing->id))
            ->handle(app(SessionContentService::class), app(GarminHealthSummary::class));

        $this->assertSame('expired', $old->fresh()->status);
        $this->assertSame(
            1,
            SessionRecommendation::where('status', 'pending')->count(),
            'Es darf nur eine offene Entscheidung geben',
        );
    }
}
