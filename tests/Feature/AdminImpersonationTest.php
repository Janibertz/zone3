<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminImpersonationController as Impersonation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Als Athlet anmelden — und wieder zurück.
 *
 * Der schnellste Weg, eine Meldung zu verstehen, ist die Seite zu sehen,
 * die der Athlet sieht. „Im Trainingsplan ist heute eine Lücke" liess sich
 * bis hierher nur über Datenbankabfragen nachvollziehen.
 *
 * Weil die Funktion einen Account übernimmt, ist der grösste Teil dieser
 * Datei kein Funktions-, sondern ein Grenztest: was dabei NICHT passieren
 * darf.
 */
class AdminImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function athlete(): User
    {
        return User::factory()->create(['is_admin' => false]);
    }

    // ── Der Normalfall ───────────────────────────────────────────────────

    public function test_an_admin_can_look_through_an_athletes_eyes(): void
    {
        $admin   = $this->admin();
        $athlete = $this->athlete();

        $this->actingAs($admin)
            ->post("/admin/users/{$athlete->id}/impersonate")
            ->assertRedirect(route('dashboard'));

        $this->assertSame($athlete->id, Auth::id());
        $this->assertSame($admin->id, session(Impersonation::SESSION_KEY));
    }

    public function test_and_can_come_back(): void
    {
        $admin   = $this->admin();
        $athlete = $this->athlete();

        $this->actingAs($admin)->post("/admin/users/{$athlete->id}/impersonate");
        $this->post('/impersonate/stop')->assertRedirect(route('admin.users.index'));

        $this->assertSame($admin->id, Auth::id());
        $this->assertFalse(session()->has(Impersonation::SESSION_KEY));
    }

    // ── Was nicht passieren darf ─────────────────────────────────────────

    /**
     * Die wichtigste Zusicherung der ganzen Datei.
     *
     * Während der Übernahme ist man der Athlet — und der ist kein Admin.
     * Wäre das anders, hätte man einen stillen Weg, unter fremdem Namen
     * administrative Dinge zu tun.
     */
    public function test_an_impersonated_session_has_no_admin_rights(): void
    {
        $athlete = $this->athlete();

        $this->actingAs($this->admin())->post("/admin/users/{$athlete->id}/impersonate");

        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/system')->assertForbidden();
    }

    /**
     * Kein Admin übernimmt einen Admin. Sonst liesse sich verschleiern,
     * wer eine administrative Änderung tatsächlich vorgenommen hat.
     */
    public function test_an_admin_cannot_be_impersonated(): void
    {
        $admin = $this->admin();
        $other = $this->admin();

        $this->actingAs($admin)->post("/admin/users/{$other->id}/impersonate");

        $this->assertSame($admin->id, Auth::id(), 'Die Anmeldung darf nicht gewechselt haben');
        $this->assertFalse(session()->has(Impersonation::SESSION_KEY));
    }

    public function test_a_normal_user_cannot_impersonate_anyone(): void
    {
        $athlete = $this->athlete();
        $victim  = $this->athlete();

        $this->actingAs($athlete)
            ->post("/admin/users/{$victim->id}/impersonate")
            ->assertForbidden();

        $this->assertSame($athlete->id, Auth::id());
    }

    /**
     * Keine Übernahme in der Übernahme — sonst ginge der Rückweg verloren,
     * weil in der Session nur eine Herkunft steht.
     */
    public function test_no_second_takeover_while_one_is_running(): void
    {
        $admin = $this->admin();
        $first = $this->athlete();
        $other = $this->athlete();

        $this->actingAs($admin)->post("/admin/users/{$first->id}/impersonate");
        $this->post("/admin/users/{$other->id}/impersonate");

        $this->assertSame($first->id, Auth::id(), 'Die erste Übernahme bleibt bestehen');
        $this->assertSame($admin->id, session(Impersonation::SESSION_KEY));
    }

    /**
     * Das Beenden hängt bewusst NICHT an der Admin-Middleware — sonst käme
     * man aus der Übernahme nicht mehr heraus, weil man in ihr kein Admin
     * ist. Ohne laufende Übernahme darf es trotzdem nichts tun.
     */
    public function test_stopping_without_a_takeover_changes_nothing(): void
    {
        $athlete = $this->athlete();

        $this->actingAs($athlete)
            ->post('/impersonate/stop')
            ->assertRedirect(route('dashboard'));

        $this->assertSame($athlete->id, Auth::id());
    }

    /**
     * Verliert der Herkunfts-Account in der Zwischenzeit seine Adminrechte,
     * ist der Rückweg kein Rückweg mehr — dann lieber abmelden als jemanden
     * ohne Rechte in einen Admin-Account setzen.
     */
    public function test_a_demoted_admin_is_logged_out_instead(): void
    {
        $admin   = $this->admin();
        $athlete = $this->athlete();

        $this->actingAs($admin)->post("/admin/users/{$athlete->id}/impersonate");

        $admin->forceFill(['is_admin' => false])->save();

        $this->post('/impersonate/stop')->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
