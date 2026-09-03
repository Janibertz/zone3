<?php

namespace Tests\Feature;

use App\Jobs\SendNewsletterJob;
use App\Mail\NewsletterMail;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Der Newsletter geht raus.
 *
 * Am 04.06. schlug `SendNewsletterJob` viermal fehl: „Undefined variable
 * $subject" aus der Blade-Vorlage. Der Grund war subtil — `NewsletterMail`
 * hatte eine Eigenschaft `$subject`, und `Illuminate\Mail\Mailable` hat sie
 * auch. Die Vorlage bekam die des Elternteils, und die war leer.
 *
 * Behoben wurde das am selben Tag (`571f67c`, umbenannt in `$mailSubject`);
 * die vier Einträge in `failed_jobs` sind Altlasten von davor. Was fehlte,
 * war ein Test: die Kollision fällt beim Bauen nicht auf, beim Absenden
 * schon — und dann an vierhundert Empfängern gleichzeitig.
 */
class NewsletterSendTest extends TestCase
{
    use RefreshDatabase;

    private function newsletter(): Newsletter
    {
        return Newsletter::create([
            'created_by'   => User::factory()->create([
                'is_admin'          => true,
                // Sonst zaehlt der Autor als Empfaenger mit.
                'newsletter_opt_in' => false,
            ])->id,
            'subject'      => 'Zone3 im September',
            'preview_text' => 'Was neu ist',
            'html_content' => '<p>Der lange Lauf am Sonntag.</p>',
        ]);
    }

    /**
     * Die Vorlage wirklich rendern. `Mail::fake()` allein tut das NICHT —
     * es fängt den Versand ab, bevor Blade je angefasst wird, und hätte
     * genau diesen Fehler durchgelassen.
     */
    public function test_the_mail_renders(): void
    {
        $mail = new NewsletterMail(
            mailSubject:    'Zone3 im September',
            htmlContent:    '<p>Der lange Lauf am Sonntag.</p>',
            recipientName:  'Jan',
            unsubscribeUrl: 'https://zone3.run/newsletter/unsubscribe/abc',
        );

        $html = $mail->render();

        $this->assertStringContainsString('Zone3 im September', $html, 'Der Betreff steht im Titel');
        $this->assertStringContainsString('Hallo Jan', $html);
        $this->assertStringContainsString('Der lange Lauf am Sonntag.', $html);
        $this->assertStringContainsString('unsubscribe/abc', $html);
    }

    /**
     * Der eigentliche Fallstrick: eine Eigenschaft, die genauso heisst wie
     * eine des Elternteils. Blade bekäme dann die falsche.
     */
    public function test_the_subject_property_does_not_collide_with_the_mailable(): void
    {
        $own = collect((new \ReflectionClass(NewsletterMail::class))->getProperties())
            // getProperties() liefert auch die geerbten — gesucht ist nur,
            // was NewsletterMail selbst deklariert.
            ->filter(fn ($p) => $p->getDeclaringClass()->getName() === NewsletterMail::class)
            ->map->getName()
            ->all();

        $parent = array_column((new \ReflectionClass(\Illuminate\Mail\Mailable::class))->getProperties(), 'name');

        foreach (array_intersect($own, $parent) as $clash) {
            $this->fail("NewsletterMail::\${$clash} verdeckt dieselbe Eigenschaft in Mailable — die Vorlage bekaeme die falsche.");
        }

        $this->assertTrue(true);
    }

    // ── Der Versand ──────────────────────────────────────────────────────

    public function test_only_subscribers_get_it(): void
    {
        Mail::fake();

        $in  = User::factory()->create(['newsletter_opt_in' => true]);
        User::factory()->create(['newsletter_opt_in' => false]);

        (new SendNewsletterJob($this->newsletter()->id))->handle();

        Mail::assertSent(NewsletterMail::class, 1);
        Mail::assertSent(NewsletterMail::class, fn ($m) => $m->hasTo($in->email));
    }

    /**
     * Jeder Empfänger braucht einen Abmeldelink, der nur für ihn gilt —
     * sonst meldet ein Klick den Falschen ab.
     */
    public function test_every_recipient_gets_an_unsubscribe_token(): void
    {
        Mail::fake();

        $user = User::factory()->create(['newsletter_opt_in' => true, 'unsubscribe_token' => null]);

        (new SendNewsletterJob($this->newsletter()->id))->handle();

        $this->assertNotNull($user->fresh()->unsubscribe_token);
    }

    public function test_the_sent_count_is_written_back(): void
    {
        Mail::fake();

        User::factory()->count(3)->create(['newsletter_opt_in' => true]);
        $newsletter = $this->newsletter();

        (new SendNewsletterJob($newsletter->id))->handle();

        $this->assertSame(3, $newsletter->fresh()->sent_count);
    }
}
