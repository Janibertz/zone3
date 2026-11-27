<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InstagramPublication;
use App\Services\Instagram\InstagramClient;
use App\Services\Instagram\InstagramPublisher;
use App\Services\Instagram\InstagramSchedule;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Instagram im Admin (27.11.2026): was Zone3 postet und was Jan von Hand
 * posten muss. Die Nachricht zu einer Story mit Sticker führt auf show().
 */
class AdminInstagramController extends Controller
{
    public function index(InstagramSchedule $schedule, InstagramClient $client): Response
    {
        $rows  = InstagramPublication::all()->keyBy('slug');
        $posts = collect($schedule->all())
            ->filter(fn ($p) => $p['at']->gt(now()->subDays(14)))
            ->map(fn ($p) => $this->present($p, $rows[$p['slug']] ?? null))
            ->values();

        return Inertia::render('Admin/Instagram/Index', [
            'posts'      => $posts,
            'configured' => $client->configured(),
        ]);
    }

    public function show(string $slug, InstagramSchedule $schedule): Response
    {
        $post = $schedule->find($slug) ?? abort(404);

        return Inertia::render('Admin/Instagram/Show', [
            'post' => $this->present($post, InstagramPublication::where('slug', $slug)->first()),
        ]);
    }

    public function posted(string $slug, InstagramSchedule $schedule): RedirectResponse
    {
        $post = $schedule->find($slug) ?? abort(404);
        abort_unless(in_array($post['type'], InstagramSchedule::MANUAL, true), 422);

        InstagramPublication::updateOrCreate(['slug' => $slug], [
            'type'         => $post['type'],
            'scheduled_at' => $post['at'],
            'status'       => InstagramPublication::POSTED,
            'published_at' => now(),
            'error'        => null,
        ]);

        return redirect()->route('admin.instagram.index')->with('success', 'Als gepostet markiert.');
    }

    public function retry(string $slug, InstagramSchedule $schedule, InstagramPublisher $publisher, InstagramClient $client): RedirectResponse
    {
        $post = $schedule->find($slug) ?? abort(404);
        abort_if(in_array($post['type'], InstagramSchedule::MANUAL, true), 422);
        $row = InstagramPublication::where('slug', $slug)->first();
        abort_unless($row && in_array($row->status, [InstagramPublication::FAILED, InstagramPublication::MISSED], true), 422);
        abort_unless($client->configured(), 422);

        $result = $publisher->retry($post);

        return redirect()->route('admin.instagram.index')->with('success', "Neu angestoßen: {$result}.");
    }

    private function present(array $post, ?InstagramPublication $row): array
    {
        return [
            'slug'      => $post['slug'],
            'at'        => $post['at']->toIso8601String(),
            'type'      => $post['type'],
            'manual'    => in_array($post['type'], InstagramSchedule::MANUAL, true),
            'media'     => $post['media'],
            'cover'     => $post['cover'],
            'caption'   => $post['caption'],
            'sticker'   => $post['sticker'],
            'note'      => $post['note'],
            'status'    => $row?->status ?? ($post['at']->isFuture() ? 'planned' : 'due'),
            'error'     => $row?->error,
            'permalink' => $row?->permalink,
        ];
    }
}
