<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Kein Test spricht mit der Aussenwelt.
     *
     * Das ist keine Vorsichtsmassnahme auf Verdacht, sondern die Folge eines
     * Fehlers: `QUEUE_CONNECTION` ist in den Tests `sync`, jeder `dispatch`
     * läuft also sofort. Ein Test, der einen Aktivitätsimport auslöste,
     * stiess damit `GenerateSessionReviewJob` an — und der spricht mit
     * OpenAI. `Http::fake()` mit einem URL-Muster fängt das nicht: was nicht
     * auf das Muster passt, geht ungefragt ins Netz. Zwei Testläufe kosteten
     * so echtes Geld, und zu sehen war davon nur, dass zwei Tests vier
     * Sekunden brauchten statt zwanzig Millisekunden.
     *
     * `preventStrayRequests()` dreht das um: was nicht ausdrücklich gefälscht
     * ist, fliegt auf. Ein Test, der einen Aufruf braucht, fälscht ihn — und
     * sagt damit im Code, mit wem er zu sprechen glaubt.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }
}
