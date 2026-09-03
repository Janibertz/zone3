<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Der Systemstatus im Admin-Bereich.
 *
 * Er ist aus zwei Fehlersuchen entstanden, die beide unnötig lang gedauert
 * haben, weil die Antwort nirgends sichtbar war:
 *
 *   · Nach dem Umbau des Strava-Webhooks kamen keine Aktivitäten mehr
 *     herein. Ob der Job lief, ob er fehlschlug, woran — nicht abfragbar.
 *     Die Ursache war ein Rückstau in der Queue.
 *   · Im Trainingsplan fehlten zwei Tage komplett, während der Verlauf für
 *     genau diese Tage eine Änderung meldete.
 *
 * Deshalb liest diese Seite durchgehend die Datenbank und nie das, was
 * vorgesehen war.
 */
class AdminSystemTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /** @return array{0: User, 1: TrainingPlan} */
    private function athleteWithPlan(): array
    {
        $user = User::factory()->create();

        $event = Event::create([
            'user_id'             => $user->id,
            'name'                => 'Zielrennen',
            'event_date'          => now()->addDays(40),
            'race_distance'       => 'marathon',
            'priority'            => 'A',
            'target_time_hours'   => 3,
            'target_time_minutes' => 30,
        ]);

        $plan = TrainingPlan::create([
            'user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true,
        ]);

        return [$user, $plan];
    }

    private function unit(User $user, TrainingPlan $plan, string $date): void
    {
        DB::table('training_sessions')->insert([
            'user_id'          => $user->id,
            'training_plan_id' => $plan->id,
            'event_id'         => $plan->event_id,
            'planned_date'     => $date,
            'type'             => 'easy_run',
            'title'            => 'Lauf',
            'description'      => '',
            'intensity'        => 'low',
            'status'           => 'planned',
            'sort_order'       => 0,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    // ── Zugang ───────────────────────────────────────────────────────────

    public function test_a_normal_user_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin/system')
            ->assertForbidden();
    }

    public function test_an_admin_can_open_it(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/system')
            ->assertOk();
    }

    // ── Queues ───────────────────────────────────────────────────────────

    /**
     * Beide Queues stehen auf der Seite, auch wenn sie leer sind. „0 wartend"
     * ist eine Aussage — eine fehlende Zeile ist keine, und genau die hätte
     * bei der Webhook-Suche nichts geholfen.
     */
    public function test_both_queues_are_listed_even_when_empty(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/system');

        $queues = collect($response->viewData('page')['props']['queues'])->pluck('queue');

        $this->assertTrue($queues->contains('default'));
        $this->assertTrue($queues->contains('imports'));
    }

    /**
     * Eine Aufgabe, die lange wartet, heisst: der Worker dieser Queue
     * arbeitet nicht. Das ist die Zahl, auf die es ankommt.
     */
    public function test_a_long_waiting_job_marks_its_queue_as_stale(): void
    {
        DB::table('jobs')->insert([
            'queue'        => 'imports',
            'payload'      => json_encode(['displayName' => 'App\\Jobs\\ImportStravaActivityJob']),
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => now()->subMinutes(45)->timestamp,
            'created_at'   => now()->subMinutes(45)->timestamp,
        ]);

        $response = $this->actingAs($this->admin())->get('/admin/system');

        $imports = collect($response->viewData('page')['props']['queues'])->firstWhere('queue', 'imports');

        $this->assertSame(1, $imports['pending']);
        $this->assertTrue($imports['stale'], 'Eine 45 Minuten wartende Aufgabe muss auffallen');
        $this->assertGreaterThanOrEqual(45, $imports['waiting_min']);
    }

    // ── Fehlgeschlagene Aufgaben ─────────────────────────────────────────

    private function failedJob(string $uuid, string $class = 'App\\Jobs\\ImportStravaActivityJob'): void
    {
        DB::table('failed_jobs')->insert([
            'uuid'       => $uuid,
            'connection' => 'database',
            'queue'      => 'imports',
            'payload'    => json_encode(['displayName' => $class]),
            'exception'  => "RuntimeException: Strava lieferte nichts aus.\n#0 irgendwo",
            'failed_at'  => now(),
        ]);
    }

    public function test_failed_jobs_show_their_first_line(): void
    {
        $this->failedJob(Str::uuid()->toString());

        $props = $this->actingAs($this->admin())->get('/admin/system')->viewData('page')['props'];

        $this->assertSame(1, $props['failedJobs']['total']);
        $this->assertStringContainsString('Strava lieferte nichts aus', $props['failedJobs']['recent'][0]['reason']);
        $this->assertStringNotContainsString('#0', $props['failedJobs']['recent'][0]['reason'], 'Der Stacktrace gehoert nicht in die Liste');
    }

    public function test_an_admin_can_remove_a_failed_entry(): void
    {
        $uuid = Str::uuid()->toString();
        $this->failedJob($uuid);

        $this->actingAs($this->admin())
            ->delete("/admin/system/failed/{$uuid}")
            ->assertRedirect();

        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    // ── Plan-Lücken ──────────────────────────────────────────────────────

    /**
     * Der gemeldete Fall: Dienstag belegt, Mittwoch belegt, Donnerstag
     * nichts, Freitag wieder belegt.
     */
    public function test_a_hole_inside_a_plan_is_found(): void
    {
        [$user, $plan] = $this->athleteWithPlan();

        $this->unit($user, $plan, now()->addDay()->toDateString());
        // Der Tag dazwischen bleibt leer.
        $this->unit($user, $plan, now()->addDays(3)->toDateString());

        $props = $this->actingAs($this->admin())->get('/admin/system')->viewData('page')['props'];

        $gaps = collect($props['planHealth']['gaps'])->firstWhere('user_id', $user->id);

        $this->assertNotNull($gaps, 'Das Loch muss auffallen');
        $this->assertContains(now()->addDays(2)->toDateString(), $gaps['dates']);
    }

    public function test_a_plan_without_holes_is_not_reported(): void
    {
        [$user, $plan] = $this->athleteWithPlan();

        foreach ([1, 2, 3] as $offset) {
            $this->unit($user, $plan, now()->addDays($offset)->toDateString());
        }

        $props = $this->actingAs($this->admin())->get('/admin/system')->viewData('page')['props'];

        $this->assertNull(collect($props['planHealth']['gaps'])->firstWhere('user_id', $user->id));
    }

    /**
     * Das Schliessen kostet bewusst keinen Modellaufruf. Ein Ruhetag sagt
     * „hier steht kein Training"; ein Loch sagt gar nichts.
     */
    public function test_gaps_can_be_closed_with_rest_days(): void
    {
        [$user, $plan] = $this->athleteWithPlan();

        $this->unit($user, $plan, now()->addDay()->toDateString());
        $this->unit($user, $plan, now()->addDays(3)->toDateString());

        $this->actingAs($this->admin())
            ->post("/admin/system/plan-gaps/{$user->id}")
            ->assertRedirect();

        $filled = TrainingSession::where('user_id', $user->id)
            ->whereDate('planned_date', now()->addDays(2)->toDateString())
            ->first();

        $this->assertNotNull($filled);
        $this->assertSame('rest', $filled->type);
    }

    /**
     * Einheiten ohne Plan sind in der Datenbank und trotzdem unsichtbar —
     * die Planseite lädt nur Einheiten des aktiven Plans. Genau davon lagen
     * fünf im Bestand, ohne dass es jemand wissen konnte.
     */
    public function test_sessions_without_a_plan_are_counted(): void
    {
        [$user, $plan] = $this->athleteWithPlan();
        $this->unit($user, $plan, now()->addDay()->toDateString());

        TrainingSession::where('user_id', $user->id)->update(['training_plan_id' => null]);

        $props = $this->actingAs($this->admin())->get('/admin/system')->viewData('page')['props'];

        $this->assertSame(1, $props['planHealth']['orphans_total']);
        $this->assertSame(1, $props['planHealth']['orphans_planned']);
    }

    // ── Die Kurzfassung fuers Dashboard ──────────────────────────────────

    /**
     * Die Warnzeile auf der Übersicht kommt aus derselben Quelle wie die
     * Seite. Zwei Rechnungen hiessen zwei Wahrheiten — der wiederkehrende
     * Fehler in diesem Projekt.
     *
     * Geprüft wird `SystemHealth` selbst, nicht die Dashboard-Route: die
     * benutzt `DATE_FORMAT`, das es nur in MySQL gibt, und ist unter SQLite
     * nicht aufrufbar.
     */
    public function test_the_summary_matches_the_page(): void
    {
        $this->failedJob(Str::uuid()->toString());

        [$user, $plan] = $this->athleteWithPlan();
        $this->unit($user, $plan, now()->addDay()->toDateString());
        $this->unit($user, $plan, now()->addDays(3)->toDateString());

        $page    = $this->actingAs($this->admin())->get('/admin/system')->viewData('page')['props'];
        $summary = app(SystemHealth::class)->summary();

        $this->assertSame($page['failedJobs']['total'], $summary['failed']);
        $this->assertSame(count($page['planHealth']['gaps']), $summary['plans_with_gaps']);
        $this->assertSame($page['planHealth']['orphans_planned'], $summary['orphans_planned']);
    }

    /**
     * Und wenn nichts ist, ist auch nichts zu melden — eine Warnung, die
     * immer steht, liest bald niemand mehr.
     */
    public function test_a_healthy_system_reports_nothing(): void
    {
        $summary = app(SystemHealth::class)->summary();

        $this->assertSame(0, $summary['failed']);
        $this->assertSame(0, $summary['plans_with_gaps']);
        $this->assertSame(0, $summary['stuck']);
        $this->assertSame([], $summary['stale_queues']);
    }

    public function test_a_stale_queue_reaches_the_summary(): void
    {
        DB::table('jobs')->insert([
            'queue'        => 'imports',
            'payload'      => json_encode(['displayName' => 'App\\Jobs\\ImportStravaActivityJob']),
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => now()->subHour()->timestamp,
            'created_at'   => now()->subHour()->timestamp,
        ]);

        $this->assertSame(['imports'], app(SystemHealth::class)->summary()['stale_queues']);
    }

    // ── Derselbe Blick auf einen Athleten ────────────────────────────────

    /**
     * Auf der Nutzerseite fehlte genau das, was bei der Suche nach der
     * Plan-Lücke gebraucht wurde.
     */
    public function test_the_per_user_view_reports_the_same_gap(): void
    {
        [$user, $plan] = $this->athleteWithPlan();

        $this->unit($user, $plan, now()->addDay()->toDateString());
        $this->unit($user, $plan, now()->addDays(3)->toDateString());

        $diag = app(SystemHealth::class)->forUser($user);

        $this->assertNotNull($diag['plan']);
        $this->assertSame(2, $diag['counts']['planned']);
        $this->assertContains(now()->addDays(2)->toDateString(), $diag['gaps']);
    }

    public function test_orphaned_sessions_are_listed_for_the_athlete(): void
    {
        [$user, $plan] = $this->athleteWithPlan();
        $this->unit($user, $plan, now()->addDay()->toDateString());

        TrainingSession::where('user_id', $user->id)->update(['training_plan_id' => null]);

        $diag = app(SystemHealth::class)->forUser($user);

        $this->assertCount(1, $diag['orphans']);
        $this->assertSame(now()->addDay()->toDateString(), $diag['orphans'][0]['date']);
    }

    /**
     * Der Anlass zählt: jede Neuberechnung ist ein neuer Wurf des Modells.
     */
    public function test_revisions_are_listed_with_their_trigger(): void
    {
        [$user, $plan] = $this->athleteWithPlan();

        \App\Models\PlanRevision::create([
            'user_id'          => $user->id,
            'event_id'         => $plan->event_id,
            'training_plan_id' => $plan->id,
            'triggered_by'     => 'weekly',
            'changes'          => [['date' => '2026-09-03', 'kind' => 'removed']],
            'corrections'      => ['Longrun gekuerzt'],
        ]);

        $diag = app(SystemHealth::class)->forUser($user);

        $this->assertCount(1, $diag['revisions']);
        $this->assertSame('weekly', $diag['revisions'][0]['trigger']);
        $this->assertSame(1, $diag['revisions'][0]['changes']);
        $this->assertSame(1, $diag['revisions'][0]['corrections']);
        $this->assertNotSame('weekly', $diag['revisions'][0]['label'], 'Der Anlass bekommt einen deutschen Namen');
    }

    public function test_an_athlete_without_a_plan_does_not_break_it(): void
    {
        $diag = app(SystemHealth::class)->forUser(User::factory()->create());

        $this->assertNull($diag['plan']);
        $this->assertSame([], $diag['gaps']);
        $this->assertSame([], $diag['orphans']);
    }

    // ── Strava ───────────────────────────────────────────────────────────

    /**
     * Ein abgelaufener Zugangstoken ist KEIN Fehler.
     *
     * Strava gibt sechs Stunden, und `StravaService::fetchActivity()` holt
     * beim naechsten Abruf selbst einen neuen. Wer laenger nichts hochlaedt,
     * haette hier sonst dauerhaft eine Warnung stehen — und eine Warnung,
     * die immer steht, liest bald niemand mehr.
     */
    public function test_an_expired_access_token_is_not_a_problem(): void
    {
        $user = User::factory()->create();

        \App\Models\StravaAccount::create([
            'user_id'          => $user->id,
            'strava_id'        => 1234,
            'access_token'     => 'alt',
            'refresh_token'    => 'noch-da',
            'token_expires_at' => now()->subDays(3),
        ]);

        $strava = app(SystemHealth::class)->integrations()['strava'];

        $this->assertTrue($strava[0]['connected'], 'Mit Refresh-Token ist die Verbindung in Ordnung');
    }

    /**
     * Kaputt ist sie erst ohne Refresh-Token — dann kommt niemand mehr an
     * einen neuen Zugang, und der Athlet muss Strava neu verbinden.
     */
    public function test_a_missing_refresh_token_is(): void
    {
        $user = User::factory()->create();

        \App\Models\StravaAccount::create([
            'user_id'          => $user->id,
            'strava_id'        => 5678,
            'access_token'     => '',
            'refresh_token'    => '',
            'token_expires_at' => now()->addHours(5),
        ]);

        $strava = app(SystemHealth::class)->integrations()['strava'];

        $this->assertFalse($strava[0]['connected'], 'Ohne Refresh-Token hilft auch ein gueltiger Zugang nicht lange');
    }

    // ── Umgebung ─────────────────────────────────────────────────────────

    public function test_the_environment_block_names_both_models(): void
    {
        $props = $this->actingAs($this->admin())->get('/admin/system')->viewData('page')['props'];

        $this->assertSame(config('services.openai.model'), $props['environment']['model']);
        $this->assertSame(config('services.openai.model_mini'), $props['environment']['model_mini']);
    }
}
