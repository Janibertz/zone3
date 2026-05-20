<?php

namespace App\Http\Controllers;

use App\Models\WikiChangelog;
use App\Services\OpenAIService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function github(Request $request, OpenAIService $openAI)
    {
        // Verify GitHub signature
        $secret = config('services.github.webhook_secret');
        if ($secret) {
            $signature = $request->header('X-Hub-Signature-256');
            if (! $signature) {
                return response()->json(['error' => 'Missing signature'], 401);
            }
            $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);
            if (! hash_equals($expected, $signature)) {
                return response()->json(['error' => 'Invalid signature'], 401);
            }
        }

        $event = $request->header('X-GitHub-Event');

        // Only handle push events
        if ($event !== 'push') {
            return response()->json(['ok' => true, 'skipped' => true]);
        }

        $payload = $request->json()->all();
        $ref     = $payload['ref'] ?? '';
        $branch  = str_replace('refs/heads/', '', $ref);

        // Only track main branch
        if ($branch !== 'main') {
            return response()->json(['ok' => true, 'skipped' => true]);
        }

        $commits = collect($payload['commits'] ?? [])->map(fn ($c) => [
            'id'        => substr($c['id'] ?? '', 0, 7),
            'message'   => $c['message'] ?? '',
            'author'    => $c['author']['name'] ?? '',
            'timestamp' => $c['timestamp'] ?? '',
        ])->toArray();

        if (empty($commits)) {
            return response()->json(['ok' => true, 'skipped' => true]);
        }

        $filesChanged = collect($payload['commits'] ?? [])
            ->flatMap(fn ($c) => array_merge(
                $c['added'] ?? [],
                $c['modified'] ?? [],
                $c['removed'] ?? [],
            ))
            ->unique()
            ->values()
            ->toArray();

        $pusherName = $payload['pusher']['name'] ?? null;
        $pushedAt   = now();

        // Generate AI summary
        $aiSummary = null;
        try {
            $aiSummary = $openAI->generateChangelogSummary($commits, $filesChanged);
        } catch (\Throwable $e) {
            Log::warning('Wiki changelog: AI summary failed', ['error' => $e->getMessage()]);
        }

        WikiChangelog::create([
            'commit_sha'   => substr($payload['after'] ?? '', 0, 40),
            'branch'       => $branch,
            'pusher_name'  => $pusherName,
            'commits'      => $commits,
            'files_changed'=> $filesChanged,
            'ai_summary'   => $aiSummary,
            'pushed_at'    => $pushedAt,
        ]);

        Log::info('Wiki: GitHub push event recorded', ['branch' => $branch, 'commits' => count($commits)]);

        return response()->json(['ok' => true]);
    }
}
