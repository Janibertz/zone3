<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\User;
use App\Notifications\ContactMessageNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Impressum und Kontaktformular sind öffentlich — der zweite Kontaktweg
 * nach § 5 DDG muss ohne Konto funktionieren.
 */
class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_impressum_is_public(): void
    {
        $this->get('/impressum')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Impressum'));
    }

    public function test_a_waitlisted_account_can_still_read_the_impressum(): void
    {
        $user = User::factory()->create(['waitlisted_at' => now()]);

        $this->actingAs($user)->get('/impressum')->assertOk();
    }

    public function test_a_visitor_sends_a_message_and_the_admin_gets_a_mail(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        User::factory()->create(['is_admin' => false]);

        $this->post('/kontakt', ['name' => 'Lea', 'email' => 'lea@example.com', 'message' => 'Wann kommt die Android-App?'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $msg = ContactMessage::sole();
        $this->assertSame('lea@example.com', $msg->email);
        $this->assertNull($msg->user_id);

        Notification::assertSentOnDemand(ContactMessageNotification::class, function ($n, $channels, $notifiable) {
            return $notifiable->routes['mail'] === 'jan.anders@zone3.run'
                && $n->toMail($notifiable)->replyTo[0][0] === 'lea@example.com';
        });
        Notification::assertNotSentTo($admin, ContactMessageNotification::class);
        Notification::assertCount(1);
    }

    public function test_the_honeypot_stores_nothing(): void
    {
        Notification::fake();

        $this->post('/kontakt', ['email' => 'bot@example.com', 'message' => 'Buy cheap stuff now', 'website' => 'http://spam'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseCount('contact_messages', 0);
        Notification::assertNothingSent();
    }

    public function test_email_and_message_are_required(): void
    {
        $this->post('/kontakt', ['message' => 'kurz'])->assertSessionHasErrors(['email', 'message']);
        $this->assertDatabaseCount('contact_messages', 0);
    }

    public function test_only_admins_see_and_delete_messages(): void
    {
        $msg = ContactMessage::create(['email' => 'a@example.com', 'message' => 'Hallo, eine Frage zur App']);
        $athlete = User::factory()->create(['is_admin' => false]);
        $admin   = User::factory()->create(['is_admin' => true]);

        $this->actingAs($athlete)->get('/admin/contact')->assertForbidden();
        $this->actingAs($athlete)->delete("/admin/contact/{$msg->id}")->assertForbidden();

        $this->actingAs($admin)->get('/admin/contact')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/ContactMessages/Index')->has('messages', 1));

        $this->actingAs($admin)->patch("/admin/contact/{$msg->id}/handled")->assertRedirect();
        $this->assertNotNull($msg->fresh()->handled_at);

        $this->actingAs($admin)->delete("/admin/contact/{$msg->id}")->assertRedirect();
        $this->assertDatabaseCount('contact_messages', 0);
    }
}
