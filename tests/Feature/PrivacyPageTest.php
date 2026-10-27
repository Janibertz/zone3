<?php

namespace Tests\Feature;

use App\Models\AiLog;
use App\Models\StravaWebhookEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyPageTest extends TestCase
{
    use RefreshDatabase;

    /** Garmin und Strava prüfen die Seite ohne Konto. */
    public function test_privacy_is_public(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Privacy'));
    }

    /** Die alte Adresse steht noch in den Strava-App-Einstellungen. */
    public function test_support_redirects_permanently(): void
    {
        $this->get('/support')->assertStatus(301)->assertRedirect('/privacy');
    }

    /** /privacy verspricht: mit dem Konto verschwinden auch die KI-Protokolle. */
    public function test_deleting_a_user_removes_their_ai_logs(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();
        AiLog::create(['user_id' => $user->id, 'call_type' => 'coach_chat']);
        AiLog::create(['user_id' => $other->id, 'call_type' => 'coach_chat']);

        $user->delete();

        $this->assertSame(0, AiLog::where('user_id', $user->id)->count());
        $this->assertSame(1, AiLog::where('user_id', $other->id)->count());
        $this->assertSame(1, AiLog::count());
    }

    public function test_deleting_a_user_removes_their_strava_webhook_events(): void
    {
        $user = User::factory()->create();
        StravaWebhookEvent::create($this->webhookEvent($user));

        $user->delete();

        $this->assertSame(0, StravaWebhookEvent::count());
    }

    private function webhookEvent(User $user): array
    {
        return [
            'object_type' => 'activity',
            'aspect_type' => 'create',
            'owner_id'    => 123,
            'object_id'   => 456,
            'user_id'     => $user->id,
            'outcome'     => StravaWebhookEvent::OUTCOME_IMPORTED,
        ];
    }
}
