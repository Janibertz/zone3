<?php

namespace Tests\Feature;

use App\Models\RunnerProfile;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\AI\CoachChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoachToolTest extends TestCase
{
    use RefreshDatabase;

    private function runTool(User $user, string $tool, array $args): array
    {
        $svc    = app(CoachChatService::class);
        $method = new \ReflectionMethod(CoachChatService::class, 'executeCoachTool');
        $method->setAccessible(true);
        return $method->invoke($svc, $user->fresh(), $tool, $args);
    }

    public function test_skipping_via_chat_invalidates_daily_message_cache(): void
    {
        $today = now()->toDateString();

        $user    = User::factory()->create();
        $profile = RunnerProfile::create([
            'user_id'              => $user->id,
            'daily_message'        => 'Heute läufst du locker!',
            'daily_message_date'   => $today,
            'today_recommendation' => '{"type":"easy_run"}',
            'recommendation_date'  => $today,
        ]);

        // Insert via query builder so planned_date is stored date-only (as the real
        // MySQL DATE column does); the Eloquent 'date' cast would store a datetime in
        // sqlite and break the whereBetween range match.
        $sessionId = DB::table('training_sessions')->insertGetId([
            'user_id'      => $user->id,
            'planned_date' => $today,
            'type'         => 'easy_run',
            'title'        => 'Lockerer Lauf',
            'description'  => 'Easy',
            'intensity'    => 'low',
            'status'       => 'planned',
            'sort_order'   => 0,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $this->runTool($user, 'skip_training_sessions', [
            'date_from' => $today, 'date_to' => $today, 'reason' => 'Keine Lust',
        ]);

        $this->assertSame('skipped', TrainingSession::find($sessionId)->status);

        $profile->refresh();
        $this->assertNull($profile->daily_message);
        $this->assertNull($profile->daily_message_date);
        $this->assertNull($profile->today_recommendation);
        $this->assertNull($profile->recommendation_date);
    }
}
