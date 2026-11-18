<?php

namespace Tests\Feature;

use App\Models\AdminPush;
use App\Models\AiLog;
use App\Models\ContactMessage;
use App\Models\PushSubscription;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutReport;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Der neue Admin-Bereich (18.11.2026): Heute, Postfach, Athleten, Push
 * an einen Athleten (Telegram zuerst), System, KI-Kosten, Mehr.
 */
class AdminRedesignTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'name' => 'Ada Admin']);
    }

    private function telegramFake(): void
    {
        config(['services.telegram.bot_token' => 'TEST-TOKEN', 'app.url' => 'https://zone3.test']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    private function telegramMessages(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/sendMessage'))
            ->map(fn ($pair) => $pair[0]->data())
            ->values()->all();
    }

    public function test_athletes_cannot_open_the_new_pages(): void
    {
        $athlete = User::factory()->create();
        foreach (['/admin/dashboard', '/admin/postfach', '/admin/mehr', '/admin/push', '/admin/ki-kosten'] as $url) {
            $this->actingAs($athlete)->get($url)->assertForbidden();
        }
    }

    public function test_today_shows_what_needs_the_admin(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => 'Wanda Warte', 'waitlisted_at' => now()]);

        $this->actingAs($admin)->get('/admin/dashboard')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Dashboard')
                ->has('needsYou')
                ->has('goal.funnel')
                ->has('aiCosts.month')
                ->has('quiet')
                ->has('providers')
                ->where('adminNav.inbox', 1));
    }

    public function test_the_inbox_lists_all_four_kinds(): void
    {
        $admin = $this->admin();
        $athlete = User::factory()->create(['name' => 'Tom Tester']);
        User::factory()->create(['waitlisted_at' => now()]);
        SupportTicket::create(['user_id' => $athlete->id, 'subject' => 'Plan fehlt', 'description' => 'Kein Plan', 'type' => 'bug', 'status' => 'open']);
        ContactMessage::create(['email' => 'x@example.com', 'message' => 'Hallo, eine Frage']);
        $workout = Workout::create(['user_id' => $athlete->id, 'name' => 'Pyramide', 'type' => 'interval', 'is_public' => true, 'published_at' => now(), 'blocks' => []]);
        WorkoutReport::create(['workout_id' => $workout->id, 'user_id' => $admin->id, 'reason' => 'Unsinn']);

        $this->actingAs($admin)->get('/admin/postfach')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Inbox/Index')
                ->where('counts.open', 4)
                ->where('counts.waitlist', 1)
                ->where('counts.ticket', 1)
                ->where('counts.contact', 1)
                ->where('counts.report', 1));

        // „Freigabe lassen“ nimmt die Meldung aus dem Postfach, das Workout bleibt geteilt.
        $this->actingAs($admin)->post("/admin/postfach/workouts/{$workout->id}/keep")->assertRedirect();
        $this->assertNotNull(WorkoutReport::first()->dismissed_at);
        $this->assertTrue((bool) $workout->fresh()->is_public);

        $this->actingAs($admin)->get('/admin/postfach')
            ->assertInertia(fn (Assert $p) => $p->where('counts.report', 0)->where('counts.open', 3));
    }

    public function test_a_ticket_reply_goes_to_telegram_first(): void
    {
        Notification::fake();
        $this->telegramFake();
        $admin = $this->admin();
        $athlete = User::factory()->create(['telegram_chat_id' => 4711]);
        $ticket = SupportTicket::create(['user_id' => $athlete->id, 'subject' => 'Frage', 'description' => 'Wie?', 'type' => 'question', 'status' => 'open']);

        $this->mock(WebPushService::class)->shouldNotReceive('sendToUser');

        $this->actingAs($admin)->post("/admin/support/{$ticket->id}/reply", ['message' => 'Schau ich mir an'])
            ->assertRedirect()->assertSessionHas('success', 'Antwort gesendet, per Mail und Telegram.');

        $this->assertSame('in_progress', $ticket->fresh()->status);
        $sent = $this->telegramMessages();
        $this->assertCount(1, $sent);
        $this->assertSame(4711, (int) $sent[0]['chat_id']);
        $this->assertStringContainsString('Schau ich mir an', $sent[0]['text']);

        $this->actingAs($admin)->post("/admin/support/{$ticket->id}/reply", ['message' => 'Ist behoben', 'resolve' => true]);
        $this->assertSame('resolved', $ticket->fresh()->status);
    }

    public function test_push_reaches_telegram_users_there_and_others_by_push(): void
    {
        $this->telegramFake();
        $admin = $this->admin();
        $viaTelegram = User::factory()->create(['telegram_chat_id' => 1001, 'push_notifications_enabled' => true]);
        PushSubscription::create(['user_id' => $viaTelegram->id, 'kind' => PushSubscription::KIND_APNS, 'endpoint' => 'tok-a']);
        $viaPush = User::factory()->create(['push_notifications_enabled' => true]);
        PushSubscription::create(['user_id' => $viaPush->id, 'kind' => PushSubscription::KIND_APNS, 'endpoint' => 'tok-b']);
        User::factory()->create(); // weder noch

        $push = $this->mock(WebPushService::class);
        $push->shouldReceive('sendToUser')->once()->withArgs(fn ($u) => $u->id === $viaPush->id);

        $this->actingAs($admin)->post('/admin/push', [
            'audience' => 'all', 'title' => 'Neu', 'body' => 'Schau mal', 'url' => '/dashboard',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertCount(1, $this->telegramMessages());
        $row = AdminPush::first();
        $this->assertSame(1, $row->via_telegram);
        $this->assertSame(1, $row->via_push);
        $this->assertSame(2, $row->reached);
    }

    public function test_push_to_a_single_athlete(): void
    {
        $this->telegramFake();
        $admin = $this->admin();
        $one = User::factory()->create(['telegram_chat_id' => 77]);
        User::factory()->create(['telegram_chat_id' => 88]);

        $this->actingAs($admin)->get("/admin/push?user={$one->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Push/Index')->where('preset', $one->id));

        $this->actingAs($admin)->post('/admin/push', [
            'audience' => 'user', 'user_id' => $one->id, 'title' => 'Hallo', 'body' => 'Nur für dich', 'url' => '/plan',
        ])->assertSessionHas('success');

        $sent = $this->telegramMessages();
        $this->assertCount(1, $sent);
        $this->assertSame(77, (int) $sent[0]['chat_id']);
        $this->assertSame($one->id, AdminPush::first()->target_user_id);
    }

    public function test_push_rejects_a_foreign_link(): void
    {
        $this->actingAs($this->admin())->post('/admin/push', [
            'audience' => 'all', 'title' => 'x', 'body' => 'y', 'url' => 'https://example.com',
        ])->assertSessionHasErrors('url');
        $this->assertDatabaseCount('admin_pushes', 0);
    }

    public function test_athletes_list_and_page(): void
    {
        $admin = $this->admin();
        $athlete = User::factory()->create(['name' => 'Tom Tester', 'onboarding_completed_at' => now()]);

        $this->actingAs($admin)->get('/admin/users')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Users/Index')->has('athletes', 2));

        $this->actingAs($admin)->get("/admin/users/{$athlete->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Users/Show')
                ->where('overview.first', 'Tom')
                ->where('overview.has_plan', false)
                ->has('overview.kpis')
                ->has('overview.timeline')
                ->has('overview.connections'));
    }

    public function test_ai_costs_page(): void
    {
        $admin = $this->admin();
        AiLog::create(['user_id' => $admin->id, 'call_type' => 'coach_chat', 'model' => 'gpt-5.6-terra', 'status' => 'success', 'cost_eur' => 0.12]);

        $this->actingAs($admin)->get('/admin/ki-kosten')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/AiCosts/Index')
                ->where('costs.by_type.0.label', 'Coach-Chat')
                ->has('costs.days')
                ->has('costs.limit_eur'));
    }

    public function test_failed_jobs_are_grouped_and_can_be_dropped_together(): void
    {
        $admin = $this->admin();
        foreach (range(1, 3) as $i) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
                'payload' => json_encode(['displayName' => 'App\\Jobs\\ImportPolarExerciseJob']),
                'exception' => "RuntimeException: Polar antwortet 401\n#0 trace", 'failed_at' => now()->subMinutes($i),
            ]);
        }

        $groups = null;
        $this->actingAs($admin)->get('/admin/system')->assertOk()
            ->assertInertia(function (Assert $p) use (&$groups) {
                $p->component('Admin/System/Index')->has('failureGroups', 1)->where('failureGroups.0.count', 3);
                $groups = $p->toArray()['props']['failureGroups'];
            });

        $this->actingAs($admin)->post('/admin/system/failed/group/forget', ['uuids' => $groups[0]['uuids']])->assertRedirect();
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_more_and_access_pages(): void
    {
        $admin = $this->admin();
        User::factory()->create(['signup_source' => 'instagram']);

        $this->actingAs($admin)->get('/admin/mehr')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/More')->has('values'));

        $this->actingAs($admin)->get('/admin/invitations')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Invitations/Index')
                ->where('sources', fn ($s) => collect($s)->contains(fn ($r) => $r['source'] === 'instagram' && $r['total'] === 1))
                ->has('referrers'));
    }

    public function test_the_wiki_is_gone(): void
    {
        $this->actingAs($this->admin())->get('/admin/wiki')->assertNotFound();
        $this->post('/webhook/github')->assertNotFound();
    }
}
