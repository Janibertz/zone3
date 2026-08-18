<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Die Form der Tabelle push_subscriptions.
 *
 * `endpoint` war als TEXT angelegt, mit einem UNIQUE-Index darauf. MySQL
 * lehnt das ab (Fehler 1170) — das Anlegen scheiterte auf jeder frischen
 * Datenbank. Zweimal wurde das umgangen, ohne die Ursache anzufassen; die
 * Produktion lief nur weiter, weil die Tabelle dort schon bestand.
 *
 * SQLite ist an dieser Stelle nachsichtiger als MySQL, deshalb pruefen
 * diese Tests die Spaltenform ausdruecklich und verlassen sich nicht
 * darauf, dass die Migration einfach durchlief.
 */
class PushSubscriptionSchemaTest extends TestCase
{
    use RefreshDatabase;

    /** Der Kern: keine TEXT-Spalte unter einem Index. */
    public function test_the_endpoint_is_not_a_text_column(): void
    {
        $this->assertNotSame(
            'text',
            Schema::getColumnType('push_subscriptions', 'endpoint'),
            'endpoint muss VARCHAR sein — auf TEXT laesst MySQL keinen Index ohne Laengenangabe zu',
        );
    }

    public function test_the_unique_index_exists(): void
    {
        $this->assertTrue(
            Schema::hasIndex('push_subscriptions', 'push_subscriptions_endpoint_unique'),
        );
    }

    /** Und er wirkt auch. */
    public function test_the_same_endpoint_cannot_be_stored_twice(): void
    {
        $user = User::factory()->create();

        $row = [
            'user_id'    => $user->id,
            'endpoint'   => 'https://fcm.googleapis.com/fcm/send/beispiel-123',
            'public_key' => 'p256dh',
            'auth_token' => 'auth',
        ];

        PushSubscription::create($row);

        $this->expectException(QueryException::class);
        PushSubscription::create($row);
    }

    /** Ein realistisch langer Endpunkt passt hinein. */
    public function test_a_long_endpoint_still_fits(): void
    {
        $user = User::factory()->create();

        $endpoint = 'https://fcm.googleapis.com/fcm/send/' . str_repeat('a', 400);

        PushSubscription::create([
            'user_id'    => $user->id,
            'endpoint'   => $endpoint,
            'public_key' => 'p256dh',
            'auth_token' => 'auth',
        ]);

        $this->assertSame($endpoint, PushSubscription::first()->endpoint);
    }
}
