<?php

namespace Tests\Feature;

use App\Jobs\RegeneratePlanJob;
use App\Models\CoachMessage;
use App\Models\Event;
use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\GarminHealthSummary;
use App\Services\PlanContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Das Gedächtnis des Coaches.
 *
 * Zwei getrennte Dinge, die im UI auch getrennt zu löschen sein müssen: der
 * Gesprächsverlauf (was gesagt wurde) und die Notizen (was der Coach daraus
 * behalten hat). Und die Notizen mussten dorthin gelangen, wo sie eine
 * Konsequenz haben — in die Planerstellung.
 */
class CoachMemoryTest extends TestCase
{
    use RefreshDatabase;

    private function profile(User $user): RunnerProfile
    {
        $profile = RunnerProfile::firstOrCreate(['user_id' => $user->id]);

        // actingAs reicht genau diese Instanz an den Controller weiter. Hätte
        // die Beziehung hier ein zwischengespeichertes null, liefe der
        // Nullsafe-Operator dort ins Leere und der Test prüfte nichts.
        $user->unsetRelation('runnerProfile');

        return $profile;
    }

    // ── Verlauf und Gemerktes löschen ────────────────────────────────────

    public function test_the_conversation_can_be_cleared(): void
    {
        $user = User::factory()->onboarded()->create();

        foreach (['user', 'assistant', 'user'] as $role) {
            CoachMessage::create(['user_id' => $user->id, 'role' => $role, 'content' => 'Text']);
        }

        $this->actingAs($user)
            ->deleteJson(route('coach.messages.destroy'))
            ->assertOk()
            ->assertJson(['success' => true, 'deleted' => 3]);

        $this->assertSame(0, CoachMessage::where('user_id', $user->id)->count());
    }

    /** Der Verlauf eines anderen Athleten bleibt unberührt. */
    public function test_clearing_only_touches_the_own_conversation(): void
    {
        $user  = User::factory()->onboarded()->create();
        $other = User::factory()->onboarded()->create();

        CoachMessage::create(['user_id' => $user->id,  'role' => 'user', 'content' => 'meins']);
        CoachMessage::create(['user_id' => $other->id, 'role' => 'user', 'content' => 'fremd']);

        $this->actingAs($user)->deleteJson(route('coach.messages.destroy'))->assertOk();

        $this->assertSame(1, CoachMessage::where('user_id', $other->id)->count());
    }

    /** Gemerktes zu löschen lässt den Verlauf stehen — es sind zwei Dinge. */
    public function test_clearing_the_notes_keeps_the_conversation(): void
    {
        $user    = User::factory()->onboarded()->create();
        $profile = $this->profile($user);
        $profile->update(['coach_notes' => '- Läuft am liebsten morgens']);

        CoachMessage::create(['user_id' => $user->id, 'role' => 'user', 'content' => 'Hallo']);

        $this->actingAs($user)->deleteJson(route('coach.notes.destroy'))->assertOk();

        $this->assertNull($profile->refresh()->coach_notes);
        $this->assertSame(1, CoachMessage::where('user_id', $user->id)->count());
    }

    public function test_clearing_requires_a_session(): void
    {
        $this->deleteJson(route('coach.messages.destroy'))->assertUnauthorized();
        $this->deleteJson(route('coach.notes.destroy'))->assertUnauthorized();
    }

    // ── Notizen wachsen nicht unbegrenzt ─────────────────────────────────

    public function test_notes_are_capped_at_the_most_recent_entries(): void
    {
        $profile = $this->profile(User::factory()->onboarded()->create());

        for ($i = 1; $i <= RunnerProfile::MAX_COACH_NOTES + 8; $i++) {
            $profile->rememberNote("Notiz {$i}");
        }

        $lines = preg_split('/\R/', $profile->refresh()->coach_notes, -1, PREG_SPLIT_NO_EMPTY);

        $this->assertCount(RunnerProfile::MAX_COACH_NOTES, $lines);
        $this->assertStringContainsString('Notiz ' . (RunnerProfile::MAX_COACH_NOTES + 8), end($lines));
        $this->assertStringNotContainsString('Notiz 1]', $profile->coach_notes);
    }

    public function test_an_empty_note_is_ignored(): void
    {
        $profile = $this->profile(User::factory()->onboarded()->create());

        $profile->rememberNote('   ');

        $this->assertNull($profile->refresh()->coach_notes);
    }

    // ── Die Notizen erreichen die Planerstellung ─────────────────────────

    public function test_the_plan_context_carries_the_coach_notes(): void
    {
        $user    = User::factory()->onboarded()->create();
        $profile = $this->profile($user);
        $profile->rememberNote('Linkes Knie zwickt nach Intervallen');

        $event = Event::create([
            'user_id'             => $user->id,
            'name'                => 'Zielrennen',
            'event_date'          => now()->addDays(40),
            'race_distance'       => '10km',
            'priority'            => 'A',
            'target_time_hours'   => 0,
            'target_time_minutes' => 45,
        ]);

        $this->mock(GarminHealthSummary::class, function ($m) {
            $m->shouldReceive('forUser')->andReturn([]);
            $m->shouldReceive('toPromptSection')->andReturn('');
        });

        $context = app(PlanContextBuilder::class)->build($user->fresh(), $event);

        $this->assertStringContainsString('Knie zwickt', (string) $context->coachNotes);
    }

    // ── Die Rückfrage des Coaches hat eine Folge ─────────────────────────

    /**
     * Die Antwort landet im Gedaechtnis — nicht in einer sofortigen
     * Neuberechnung. Frueher warf sie den Restplan neu; das war der Grund,
     * warum sich ein eingeplantes Schwellentraining ueber Nacht in zwanzig
     * lockere Minuten verwandeln konnte.
     */
    public function test_answering_the_review_question_reaches_the_plan(): void
    {
        Queue::fake();

        $user  = User::factory()->onboarded()->create();
        $event = Event::create([
            'user_id'             => $user->id,
            'name'                => 'Zielrennen',
            'event_date'          => now()->addDays(40),
            'race_distance'       => 'marathon',
            'priority'            => 'A',
            'target_time_hours'   => 3,
            'target_time_minutes' => 30,
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => []]);

        $session = TrainingSession::create([
            'user_id'          => $user->id,
            'training_plan_id' => $plan->id,
            'event_id'         => $event->id,
            'planned_date'     => now()->toDateString(),
            'type'             => 'interval',
            'title'            => '6 × 800 m',
            'description'      => 'Intervalle in Zone 4.',
            'intensity'        => 'high',
            'status'           => 'completed',
            'review_question'  => 'Wie haben sich die letzten beiden Intervalle angefühlt?',
        ]);

        $this->actingAs($user)
            ->patchJson(route('training-sessions.review-feedback', $session), ['feedback' => 'Die letzten zwei waren zu hart.'])
            ->assertOk();

        $this->assertStringContainsString(
            'zu hart',
            (string) $user->fresh()->runnerProfile->coach_notes,
            'Die Antwort muss im Gedächtnis des Coaches landen'
        );

        // Und der Plan bleibt stehen. Die Antwort wirkt ueber coach_notes auf
        // die naechste planmaessige Berechnung — sie wirft nicht die laufende
        // Woche um. Wer schreibt, dass die Einheit zu hart war, meint die
        // kommenden Wochen und nicht den Plan fuer morgen frueh.
        $this->assertFalse((bool) $plan->refresh()->needs_plan_update);
        Queue::assertNotPushed(RegeneratePlanJob::class);
    }
}
