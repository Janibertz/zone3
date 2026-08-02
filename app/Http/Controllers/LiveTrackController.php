<?php

namespace App\Http\Controllers;

use App\Models\LiveTrack;
use App\Services\LiveTrackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class LiveTrackController extends Controller
{
    // ── Öffentlich ───────────────────────────────────────────────────────

    /**
     * Die öffentliche Verfolgerseite. Kein Login, Zugang allein über den
     * unratebaren Slug. Ausgeliefert wird nur, was der Läufer freigegeben
     * hat — Name und Profilbild bleiben bewusst draußen.
     */
    public function show(string $slug)
    {
        $track = LiveTrack::where('slug', $slug)->firstOrFail();

        return Inertia::render('Live/Show', [
            'track' => $this->publicPayload($track),
        ]);
    }

    /** Nur der Datenstand — die Seite pollt das im Minutentakt nach. */
    public function data(string $slug)
    {
        $track = LiveTrack::where('slug', $slug)->firstOrFail();

        return response()->json($this->publicPayload($track))
            ->header('Cache-Control', 'public, max-age=20');
    }

    /**
     * Was die öffentliche Seite zu sehen bekommt. Bewusst eng gehalten:
     * kein Name, keine User-ID, kein Garmin-Token.
     *
     * Die Karte wird bewusst NICHT als Garmin-iframe eingebunden: dessen
     * Adresse enthält Session-ID und Token, die damit im Quelltext jeder
     * öffentlichen Seite stünden. Stattdessen zeichnen wir die Strecke aus
     * den Positionen, die wir ohnehin schon abrufen.
     */
    private function publicPayload(LiveTrack $track): array
    {
        $state  = $track->state ?? [];
        $series = $track->series ?? [];

        $path = collect($series)
            ->filter(fn ($p) => isset($p['la'], $p['lo']))
            ->map(fn ($p) => [$p['la'], $p['lo']])
            ->values()
            ->all();

        return [
            'title'        => $track->title,
            'startsAt'     => $track->starts_at->toIso8601String(),
            'yardKm'       => (float) $track->yard_km,
            'targetYards'  => $track->target_yards,
            'isActive'     => $track->is_active,

            'path'         => $path,
            'position'     => (isset($state['lat'], $state['lon']))
                ? [$state['lat'], $state['lon']]
                : null,

            'distanceKm'   => isset($state['distanceM']) ? round($state['distanceM'] / 1000, 2) : null,
            'durationSec'  => $state['durationSec'] ?? null,
            'heartRate'    => $state['hr'] ?? null,
            'paceSecPerKm' => (isset($state['speed']) && $state['speed'] > 0.5)
                ? (int) round(1000 / $state['speed'])
                : null,

            'lastPointAt'  => isset($state['lastPointMs'])
                ? \Carbon\Carbon::createFromTimestampMs($state['lastPointMs'])->toIso8601String()
                : null,

            // Nur die Kurvenwerte — Positionen stecken bereits in `path`.
            'series'       => collect($series)
                ->map(fn ($p) => ['t' => $p['t'] ?? null, 'hr' => $p['hr'] ?? null, 'p' => $p['p'] ?? null])
                ->all(),
            'stale'        => $track->last_polled_at
                ? $track->last_polled_at->lt(now()->subMinutes(5))
                : true,
        ];
    }

    // ── Verwaltung durch den Läufer ──────────────────────────────────────

    public function manage()
    {
        $track = LiveTrack::where('user_id', Auth::id())->latest()->first();

        return Inertia::render('Live/Manage', [
            'track' => $track ? [
                'id'           => $track->id,
                'slug'         => $track->slug,
                'title'        => $track->title,
                'starts_at'    => $track->starts_at->format('Y-m-d\TH:i'),
                'yard_km'      => (float) $track->yard_km,
                'target_yards' => $track->target_yards,
                'is_active'    => $track->is_active,
                'hasLiveTrack' => $track->hasLiveTrack(),
                'publicUrl'    => route('live.show', $track->slug),
                'lastPolledAt' => $track->last_polled_at?->diffForHumans(),
                'lastError'    => $track->last_error,
                'distanceKm'   => isset($track->state['distanceM'])
                    ? round($track->state['distanceM'] / 1000, 2)
                    : null,
            ] : null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'          => ['required', 'string', 'max:80'],
            'starts_at'      => ['required', 'date'],
            'yard_km'        => ['required', 'numeric', 'min:0.1', 'max:100'],
            'target_yards'   => ['nullable', 'integer', 'min:1', 'max:200'],
            'livetrack_url'  => ['nullable', 'string', 'max:400'],
            'is_active'      => ['boolean'],
        ]);

        $track = LiveTrack::firstOrNew(['user_id' => Auth::id()]);

        if (! $track->exists) {
            $track->user_id = Auth::id();
            $track->slug    = LiveTrack::newSlug();
        }

        $track->fill([
            'title'        => $data['title'],
            'starts_at'    => $data['starts_at'],
            'yard_km'      => $data['yard_km'],
            'target_yards' => $data['target_yards'] ?? null,
            'is_active'    => $data['is_active'] ?? true,
        ]);

        if ($request->filled('livetrack_url')) {
            if (! $track->setLiveTrackUrl($data['livetrack_url'])) {
                return back()->withErrors([
                    'livetrack_url' => 'Das sieht nicht nach einem LiveTrack-Link aus. Erwartet wird die Adresse mit /session/…/token/…',
                ]);
            }
        }

        $track->save();

        return back()->with('success', 'Gespeichert.');
    }

    /** Sofort abfragen, damit man vor dem Rennen sieht, ob die Verbindung steht. */
    public function testPoll(LiveTrackService $service)
    {
        $track = LiveTrack::where('user_id', Auth::id())->latest()->firstOrFail();

        if (! $track->hasLiveTrack()) {
            return back()->withErrors(['livetrack_url' => 'Erst einen LiveTrack-Link hinterlegen.']);
        }

        $service->poll($track->refresh());

        return back()->with(
            'success',
            $track->refresh()->last_error
                ? 'Abruf fehlgeschlagen — siehe Meldung unten.'
                : 'Verbindung steht.'
        );
    }
}
