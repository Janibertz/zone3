<?php

namespace Tests\Feature;

use App\Models\AiLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PruneAiLogContentTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function log(): AiLog
    {
        return AiLog::create([
            'call_type'        => 'session_review',
            'model'            => 'gpt-5.6-terra',
            'total_tokens'     => 1200,
            'cost_eur'         => 0.0042,
            'prompt_preview'   => 'HRV 48 ms …',
            'response_preview' => 'Starker Lauf …',
            'full_prompt'      => 'HRV 48 ms, Ruhepuls 46',
            'full_response'    => 'Starker Lauf, Jan.',
            'status'           => 'success',
        ]);
    }

    public function test_texts_older_than_thirty_days_are_cleared_but_costs_stay(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');
        $old = $this->log();

        Carbon::setTestNow('2026-10-25 12:00:00');
        $recent = $this->log();

        Carbon::setTestNow('2026-11-01 12:00:01');
        $this->artisan('ai:prune-logs')->assertSuccessful();

        $old = $old->fresh();
        $this->assertNull($old->full_prompt);
        $this->assertNull($old->full_response);
        $this->assertNull($old->prompt_preview);
        $this->assertNull($old->response_preview);
        $this->assertSame(1200, $old->total_tokens);
        $this->assertSame(0.0042, $old->cost_eur);
        $this->assertSame('success', $old->status);

        $this->assertSame('HRV 48 ms, Ruhepuls 46', $recent->fresh()->full_prompt);
    }

    public function test_the_command_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('ai:prune-logs');
    }
}
