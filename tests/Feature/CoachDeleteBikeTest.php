<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\AI\CoachChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * „Die 30 min Rad von heute bitte streichen" (22.11.2026): der Coach rief
 * delete_training_session mit type=bike auf. Die Radeinheit steht aber als
 * cross_training + VirtualRide, der Filter verglich `type` exakt, fand nichts,
 * und der Coach sagte, es gebe keine geplante Radeinheit — während sie auf
 * Heute, im Widget und in der Live Activity stand.
 */
class CoachDeleteBikeTest extends TestCase
{
    use RefreshDatabase;

    private function runTool(User $user, string $tool, array $args): array
    {
        $method = new \ReflectionMethod(CoachChatService::class, 'executeCoachTool');
        $method->setAccessible(true);

        return $method->invoke(app(CoachChatService::class), $user->fresh(), $tool, $args);
    }

    private function makeSession(User $user, TrainingPlan $plan, string $date, string $type, ?string $sport, string $status, int $order): int
    {
        return DB::table('training_sessions')->insertGetId([
            'user_id'          => $user->id,
            'training_plan_id' => $plan->id,
            'planned_date'     => $date,
            'type'             => $type,
            'sport_type'       => $sport,
            'title'            => $sport ? 'Rad locker' : 'Longrun',
            'description'      => '',
            'duration_min'     => $sport ? 30 : 105,
            'intensity'        => 'low',
            'status'           => $status,
            'sort_order'       => $order,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    /** @return array{0: User, 1: TrainingPlan, 2: int, 3: int} */
    private function sundayWithRunDoneAndBikeOpen(): array
    {
        $user  = User::factory()->create();
        $event = Event::create([
            'user_id' => $user->id, 'name' => 'HM', 'event_date' => now()->addDays(100),
            'race_distance' => 'half_marathon', 'priority' => 'A',
        ]);
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);

        $today = now()->toDateString();
        $run   = $this->makeSession($user, $plan, $today, 'long_run', null, 'completed', 0);
        $bike  = $this->makeSession($user, $plan, $today, 'cross_training', 'VirtualRide', 'planned', 1);

        return [$user, $plan, $run, $bike];
    }

    public function test_type_bike_finds_the_bike_session(): void
    {
        [$user, , $run, $bike] = $this->sundayWithRunDoneAndBikeOpen();

        $result = $this->runTool($user, 'delete_training_session', ['date' => now()->toDateString(), 'type' => 'bike']);

        $this->assertStringContainsString('geloescht', $result['message']);
        $this->assertNull(TrainingSession::find($bike));
        $this->assertSame('completed', TrainingSession::find($run)->status);
    }

    public function test_german_and_zwift_names_work_too(): void
    {
        foreach (['Rad', 'zwift', 'VirtualRide', 'cross_training'] as $word) {
            [$user, , , $bike] = $this->sundayWithRunDoneAndBikeOpen();
            $this->runTool($user, 'delete_training_session', ['date' => now()->toDateString(), 'type' => $word]);
            $this->assertNull(TrainingSession::find($bike), $word);
        }
    }

    public function test_a_type_that_matches_nothing_names_what_is_there(): void
    {
        [$user, , , $bike] = $this->sundayWithRunDoneAndBikeOpen();

        $result = $this->runTool($user, 'delete_training_session', ['date' => now()->toDateString(), 'type' => 'swim']);

        $this->assertStringContainsString('Rad locker', $result['message']);
        $this->assertNotNull(TrainingSession::find($bike));
    }
}
