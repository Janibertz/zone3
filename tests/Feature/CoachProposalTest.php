<?php

namespace Tests\Feature;

use App\Models\CoachMessage;
use App\Models\Event;
use App\Models\SessionRecommendation;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\AI\CoachChatService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Eigene Ideen des Coaches sind Vorschläge (Entscheidung von Jan,
 * 29.10.2026): bestellt der Athlet eine Änderung, setzt der Coach sie um;
 * hält der Coach selbst etwas für besser, entscheidet der Athlet per Knopf.
 */
class CoachProposalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-29 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function athlete(): array
    {
        $user  = User::factory()->onboarded()->create();
        $event = Event::create([
            'user_id'             => $user->id,
            'name'                => 'Halbmarathon',
            'event_date'          => now()->addDays(40),
            'race_distance'       => 'half_marathon',
            'priority'            => 'A',
            'target_time_hours'   => 1,
            'target_time_minutes' => 29,
        ]);
        $plan = TrainingPlan::create([
            'user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true,
        ]);

        return [$user, $plan];
    }

    private function plannedSession(User $user, TrainingPlan $plan, string $date): int
    {
        return DB::table('training_sessions')->insertGetId([
            'user_id'          => $user->id,
            'training_plan_id' => $plan->id,
            'planned_date'     => $date,
            'type'             => 'tempo_run',
            'title'            => 'Schwelle 3×10 min',
            'description'      => 'Schwelle',
            'distance_km'      => 12,
            'duration_min'     => 60,
            'intensity'        => 'high',
            'status'           => 'planned',
            'sort_order'       => 0,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    private function runTool(User $user, string $tool, array $args): array
    {
        $method = new \ReflectionMethod(CoachChatService::class, 'executeCoachTool');

        return $method->invoke(app(CoachChatService::class), $user->fresh(), $tool, $args);
    }

    private function propose(User $user, string $date): array
    {
        return $this->runTool($user, 'propose_session_change', [
            'date'         => $date,
            'reason'       => 'Nach drei harten Tagen lieber locker.',
            'type'         => 'easy_run',
            'title'        => 'Locker 40 min',
            'distance_km'  => 7,
            'duration_min' => 40,
        ]);
    }

    public function test_a_proposal_leaves_the_plan_untouched(): void
    {
        [$user, $plan] = $this->athlete();
        $saturday = now()->addDays(2)->toDateString();
        $id       = $this->plannedSession($user, $plan, $saturday);

        $result = $this->propose($user, $saturday);

        $session = TrainingSession::find($id);
        $this->assertSame('tempo_run', $session->type);
        $this->assertNull($session->pinned_at);

        $rec = SessionRecommendation::sole();
        $this->assertSame(SessionRecommendation::SOURCE_COACH, $rec->source);
        $this->assertSame('pending', $rec->status);
        $this->assertSame('Schwelle 3×10 min', $rec->before['title']);
        $this->assertSame('Locker 40 min', $rec->after['title']);
        $this->assertSame('proposal', $result['action']['type']);
        $this->assertSame($rec->id, $result['action']['recommendation_id']);
        $this->assertArrayNotHasKey('reload', $result['action']);
    }

    public function test_accepting_applies_and_pins(): void
    {
        [$user, $plan] = $this->athlete();
        $saturday = now()->addDays(2)->toDateString();
        $id       = $this->plannedSession($user, $plan, $saturday);
        $this->propose($user, $saturday);

        $this->actingAs($user)
            ->postJson(route('recommendations.accept', SessionRecommendation::sole()))
            ->assertOk()
            ->assertJson(['status' => 'accepted']);

        $session = TrainingSession::find($id);
        $this->assertSame('easy_run', $session->type);
        $this->assertSame('Locker 40 min', $session->title);
        $this->assertEquals(7, $session->distance_km);
        $this->assertNotNull($session->pinned_at);
    }

    public function test_rejecting_keeps_the_plan(): void
    {
        [$user, $plan] = $this->athlete();
        $saturday = now()->addDays(2)->toDateString();
        $id       = $this->plannedSession($user, $plan, $saturday);
        $this->propose($user, $saturday);

        $this->actingAs($user)
            ->postJson(route('recommendations.reject', SessionRecommendation::sole()))
            ->assertOk()
            ->assertJson(['status' => 'rejected']);

        $this->assertSame('tempo_run', TrainingSession::find($id)->type);
    }

    public function test_a_second_proposal_replaces_the_first(): void
    {
        [$user, $plan] = $this->athlete();
        $saturday = now()->addDays(2)->toDateString();
        $this->plannedSession($user, $plan, $saturday);

        $this->propose($user, $saturday);
        $this->propose($user, $saturday);

        $this->assertSame(1, SessionRecommendation::where('status', 'pending')->count());
        $this->assertSame(1, SessionRecommendation::where('status', 'expired')->count());
    }

    public function test_no_proposal_for_an_empty_day(): void
    {
        [$user] = $this->athlete();

        $result = $this->propose($user, now()->addDays(2)->toDateString());

        $this->assertNull($result['action']);
        $this->assertSame(0, SessionRecommendation::count());
    }

    /** Nach dem Neuladen zeigt der Chat den Stand, nicht wieder die Knöpfe. */
    public function test_the_history_carries_the_current_status(): void
    {
        [$user, $plan] = $this->athlete();
        $saturday = now()->addDays(2)->toDateString();
        $this->plannedSession($user, $plan, $saturday);
        $result = $this->propose($user, $saturday);

        CoachMessage::create([
            'user_id' => $user->id, 'role' => 'assistant', 'content' => 'Mein Vorschlag',
            'channel' => CoachMessage::CHANNEL_WEB, 'actions' => [$result['action']],
        ]);
        SessionRecommendation::sole()->reject();

        $this->actingAs($user)->getJson(route('coach.messages'))
            ->assertOk()
            ->assertJsonPath('messages.0.actions.0.type', 'proposal')
            ->assertJsonPath('messages.0.actions.0.status', 'rejected');
    }

    /**
     * Ein Vorschlag für Samstag darf den für heute auf dem Dashboard nicht
     * verdecken — vorher galt nur der neueste offene Vorschlag.
     */
    public function test_a_later_proposal_does_not_hide_todays_on_the_dashboard(): void
    {
        [$user, $plan] = $this->athlete();
        $today    = now()->toDateString();
        $saturday = now()->addDays(2)->toDateString();
        $this->plannedSession($user, $plan, $today);
        $this->plannedSession($user, $plan, $saturday);

        $this->propose($user, $today);
        $todayRec = SessionRecommendation::latest('id')->first();
        $this->propose($user, $saturday);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('coachRecommendation.id', $todayRec->id));
    }
}
