<?php

namespace Tests\Feature;

use App\Models\CoachMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Der Chatverlauf.
 *
 * Die Abfrage sortierte aufsteigend und schnitt bei 50 ab — das liefert die
 * AELTESTEN fünfzig Nachrichten. Wer laenger dabei ist, sah beim Oeffnen
 * des Chats deshalb immer denselben Verlauf von vor Monaten, waehrend alles
 * Neue zwar gespeichert, aber nie angezeigt wurde.
 */
class CoachHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function userWithMessages(int $count): User
    {
        $user = User::factory()->onboarded()->create();

        for ($i = 1; $i <= $count; $i++) {
            CoachMessage::create([
                'user_id'    => $user->id,
                'role'       => $i % 2 === 1 ? 'user' : 'assistant',
                'content'    => "Nachricht {$i}",
                'created_at' => now()->subMinutes($count - $i),
            ]);
        }

        return $user;
    }

    /** Kurze Verlaeufe kommen vollstaendig und in der richtigen Reihenfolge. */
    public function test_a_short_history_is_returned_in_full(): void
    {
        $user = $this->userWithMessages(6);

        $messages = $this->actingAs($user)->getJson(route('coach.messages'))
            ->assertOk()
            ->json('messages');

        $this->assertCount(6, $messages);
        $this->assertSame('Nachricht 1', $messages[0]['content']);
        $this->assertSame('Nachricht 6', $messages[5]['content']);
    }

    /** Genau der Fehler: bei mehr als fünfzig kamen die aeltesten. */
    public function test_a_long_history_returns_the_newest_messages(): void
    {
        $user = $this->userWithMessages(120);

        $messages = $this->actingAs($user)->getJson(route('coach.messages'))
            ->assertOk()
            ->json('messages');

        $this->assertCount(50, $messages);
        $this->assertSame('Nachricht 71',  $messages[0]['content'],  'Der Ausschnitt muss am Ende liegen');
        $this->assertSame('Nachricht 120', $messages[49]['content'], 'Die letzte Nachricht muss dabei sein');
    }

    /** Nach dem Schreiben steht die Antwort sofort im Verlauf. */
    public function test_a_new_message_appears_in_the_history(): void
    {
        $user = $this->userWithMessages(60);

        CoachMessage::create([
            'user_id' => $user->id,
            'role'    => 'user',
            'content' => 'Ganz frisch',
        ]);

        $messages = $this->actingAs($user)->getJson(route('coach.messages'))->json('messages');

        $this->assertSame('Ganz frisch', end($messages)['content']);
    }

    /** Fremde Verlaeufe bleiben fremd. */
    public function test_the_history_is_per_athlete(): void
    {
        $mine = $this->userWithMessages(3);
        $this->userWithMessages(3);

        $messages = $this->actingAs($mine)->getJson(route('coach.messages'))->json('messages');

        $this->assertCount(3, $messages);
    }
}
