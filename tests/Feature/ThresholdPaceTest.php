<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OpenAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ThresholdPaceTest extends TestCase
{
    use RefreshDatabase;

    private function activities(): array
    {
        return [
            ['name' => 'Tempo', 'average_speed' => 3.4, 'distance' => 12000, 'moving_time' => 3500, 'average_heartrate' => 178, 'start_date' => '2026-06-04'],
            ['name' => 'Easy',  'average_speed' => 3.0, 'distance' => 9000,  'moving_time' => 3000, 'average_heartrate' => 150, 'start_date' => '2026-06-01'],
        ];
    }

    public function test_empty_completion_is_treated_as_failure_and_logged(): void
    {
        // Reasoning model burned the whole budget → empty content, finish_reason "length".
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => ''], 'finish_reason' => 'length']],
            'usage'   => ['prompt_tokens' => 1500, 'completion_tokens' => 3000, 'total_tokens' => 4500],
        ], 200)]);

        $user   = User::factory()->create();
        $result = app(OpenAIService::class)->forUser($user->id)
            ->calculateThresholdPaceWithAI($this->activities(), 182);

        $this->assertNull($result);
        $this->assertDatabaseHas('ai_logs', [
            'call_type' => 'threshold_pace',
            'status'    => 'error',
        ]);
    }

    public function test_valid_json_returns_parsed_pace(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => '{"threshold_pace":"4:30"}'], 'finish_reason' => 'stop']],
            'usage'   => ['prompt_tokens' => 1500, 'completion_tokens' => 120, 'total_tokens' => 1620],
        ], 200)]);

        $user   = User::factory()->create();
        $result = app(OpenAIService::class)->forUser($user->id)
            ->calculateThresholdPaceWithAI($this->activities(), 182);

        $this->assertEqualsWithDelta(4.5, $result, 0.001); // 4:30 = 4.5 min/km
        $this->assertDatabaseHas('ai_logs', [
            'call_type' => 'threshold_pace',
            'status'    => 'success',
        ]);
    }
}
