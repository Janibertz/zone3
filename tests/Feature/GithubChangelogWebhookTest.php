<?php

namespace Tests\Feature;

use App\Models\AiLog;
use App\Models\WikiChangelog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Der Changelog im Admin-Wiki kommt ohne KI aus.
 *
 * Jeder Push auf main loeste eine bezahlte Zusammenfassung aus — fuer einen
 * Text, den die Commit-Nachrichten darunter schon erzaehlen. Seit
 * 28.10.2026 nicht mehr (Entscheidung von Jan).
 */
class GithubChangelogWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_push_is_recorded_without_calling_openai(): void
    {
        config(['services.github.webhook_secret' => 'geheim']);
        Http::fake();

        $payload = json_encode([
            'ref'     => 'refs/heads/main',
            'after'   => str_repeat('a', 40),
            'pusher'  => ['name' => 'Janibertz'],
            'commits' => [[
                'id'        => str_repeat('b', 40),
                'message'   => 'Flex fuer Hintergrund-Jobs',
                'author'    => ['name' => 'Jan'],
                'timestamp' => now()->toIso8601String(),
                'added'     => [],
                'modified'  => ['app/Services/AI/OpenAIClient.php'],
                'removed'   => [],
            ]],
        ]);

        $this->call('POST', '/webhook/github', [], [], [], [
            'CONTENT_TYPE'             => 'application/json',
            'HTTP_X_GITHUB_EVENT'      => 'push',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $payload, 'geheim'),
        ], $payload)->assertOk();

        $entry = WikiChangelog::first();
        $this->assertNotNull($entry);
        $this->assertNull($entry->ai_summary);

        Http::assertNothingSent();
        $this->assertSame(0, AiLog::count());
    }
}
