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
    public function show(Request $request, string $slug)
    {
        $track = LiveTrack::where('slug', $slug)->firstOrFail();
        $isCrew = $track->crewKeyMatches($request->query('crew'));

        return Inertia::render('Live/Show', [
            'track'   => $this->publicPayload($track),
            'isCrew'  => $isCrew,
            'crewKey' => $isCrew ? $request->query('crew') : null,
        ]);
    }

    /** Nur der Datenstand — die Seite pollt das im Minutentakt nach. */
    public function data(Request $request, string $slug)
    {
        $track = LiveTrack::where('slug', $slug)->firstOrFail();

        // Fuer die Crew nicht zwischenspeichern, sonst sieht sie ihre
        // eigene Aenderung verzoegert.
        $isCrew = $track->crewKeyMatches($request->query('crew'));

        return response()->json($this->publicPayload($track))
            ->header('Cache-Control', $isCrew ? 'no-store' : 'public, max-age=20');
    }

    /**
     * Steuerung durch die Crew. Zugang allein ueber den eigenen
     * Crew-Schluessel — der oeffentliche Slug reicht ausdruecklich nicht.
     */
    public function crewUpdate(Request $request, string $slug)
    {
        $track = LiveTrack::where('slug', $slug)->firstOrFail();

        abort_unless($track->crewKeyMatches($request->input('crew')), 403);

        $data = $request->validate([
            'crew'            => ['required', 'string'],
            // Endgueltige Rundenzahl. Gesetzt heisst: Rennen vorbei.
            'stopped_at_yard' => ['nullable', 'integer', 'min:0', 'max:200'],
            'note'            => ['nullable', 'string', 'max:200'],
            'delete_note'     => ['nullable', 'string'],
            // Startzeit nachjustieren, falls das Rennen spaeter losging.
            'starts_at'       => ['nullable', 'date'],
        ]);

        $changes = [];

        // Rennende: die Rundenzahl allein entscheidet, kein zusaetzlicher Status.
        if ($request->has('stopped_at_yard')) {
            $changes['stopped_at_yard'] = $data['stopped_at_yard'];
        }

        if (! empty($data['starts_at'])) {
            $changes['starts_at'] = $data['starts_at'];
        }

        // Ticker — neueste Meldung vorn, gedeckelt.
        if (! empty($data['note'])) {
            $notes = $track->notes ?? [];
            array_unshift($notes, [
                'id'   => (string) \Illuminate\Support\Str::uuid(),
                'at'   => now()->toIso8601String(),
                'text' => trim($data['note']),
            ]);
            $changes['notes'] = array_slice($notes, 0, 60);
        }

        if (! empty($data['delete_note'])) {
            $changes['notes'] = array_values(array_filter(
                $track->notes ?? [],
                fn ($n) => ($n['id'] ?? null) !== $data['delete_note']
            ));
        }

        if ($changes) $track->update($changes);

        return response()->json($this->publicPayload($track->refresh()));
    }

    /**
     * Was die öffentliche Seite zu sehen bekommt. Bewusst eng gehalten:
     * kein Name, keine User-ID.
     *
     * Zur Karte: Garmin blockt den serverseitigen Abruf der Positionen
     * (Cloudflare, 403 auch bei falschem Token). Ohne eigene Positionen
     * bleibt nur Garmins Seite im iframe — deren Adresse enthält allerdings
     * den LiveTrack-Token. Deshalb ist das eine bewusste Entscheidung des
     * Läufers über `embed_map` und nicht der Standard.
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
            'assumedPaceSec' => (int) $track->assumed_pace_sec,
            'targetYards'  => $track->target_yards,
            'isActive'     => $track->is_active,

            // Endgueltige Rundenzahl. Solange null, rechnet die Uhr weiter.
            'stoppedAtYard' => $track->stopped_at_yard,
            'notes'         => $track->notes ?? [],

            // Garmins Karte. Nur wenn ausdrücklich eingeschaltet, denn die
            // Adresse enthält den LiveTrack-Token.
            'mapUrl'       => $track->embed_map ? $track->liveTrackUrl() : null,

            'path'         => $path,
            'position'     => (isset($state['lat'], $state['lon']))
                ? [$state['lat'], $state['lon']]
                : null,

            // Distanz aus Garmin, falls sie ankommt. Sonst rechnet die Seite
            // sie aus den Runden — bei fester Rundenlänge ist das ohnehin
            // genauer als GPS, das über 24 Stunden wegdriftet.
            'distanceKm'   => isset($state['distanceM']) ? round($state['distanceM'] / 1000, 2) : null,
            'durationSec'  => $state['durationSec'] ?? null,
        ];
    }

    // ── Verwaltung durch den Läufer ──────────────────────────────────────

    public function manage()
    {
        $track = LiveTrack::where('user_id', Auth::id())->latest()->first();

        // Einträge von vor der Crew-Funktion haben noch keinen Schlüssel.
        // Beim Aufruf dieser Seite nachtragen, statt eine kaputte URL zu zeigen.
        if ($track && ! $track->crew_key) {
            $track->forceFill(['crew_key' => LiveTrack::newCrewKey()])->save();
        }

        return Inertia::render('Live/Manage', [
            'track' => $track ? [
                'id'           => $track->id,
                'slug'         => $track->slug,
                'title'        => $track->title,
                'starts_at'    => $track->starts_at->format('Y-m-d\TH:i'),
                'yard_km'      => (float) $track->yard_km,
                'assumed_pace_sec' => (int) $track->assumed_pace_sec,
                'target_yards' => $track->target_yards,
                'is_active'    => $track->is_active,
                'embed_map'    => $track->embed_map,
                'stopped_at_yard' => $track->stopped_at_yard,
                'hasLiveTrack' => $track->hasLiveTrack(),
                'publicUrl'    => route('live.show', $track->slug),
                'crewUrl'      => route('live.show', $track->slug) . '?crew=' . $track->crew_key,
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
            'assumed_pace_sec' => ['required', 'integer', 'min:120', 'max:1800'],
            'target_yards'   => ['nullable', 'integer', 'min:1', 'max:200'],
            'livetrack_url'  => ['nullable', 'string', 'max:400'],
            'is_active'      => ['boolean'],
            'embed_map'      => ['boolean'],
        ]);

        $track = LiveTrack::firstOrNew(['user_id' => Auth::id()]);

        if (! $track->exists) {
            $track->user_id = Auth::id();
            $track->slug    = LiveTrack::newSlug();
        }

        // Bestandseintraege von vor der Crew-Funktion nachruesten.
        if (! $track->crew_key) {
            $track->crew_key = LiveTrack::newCrewKey();
        }

        $track->fill([
            'title'        => $data['title'],
            'starts_at'    => $data['starts_at'],
            'yard_km'      => $data['yard_km'],
            'assumed_pace_sec' => $data['assumed_pace_sec'],
            'target_yards' => $data['target_yards'] ?? null,
            'is_active'    => $data['is_active'] ?? true,
            'embed_map'    => $data['embed_map'] ?? false,
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

    /**
     * Endgültige Rundenzahl setzen oder wieder freigeben. Solange sie leer
     * ist, zählt die Uhr automatisch weiter — dass jemand aufgehört hat,
     * kann sie nicht wissen.
     */
    public function finish(Request $request)
    {
        $data = $request->validate([
            'stopped_at_yard' => ['nullable', 'integer', 'min:0', 'max:200'],
        ]);

        $track = LiveTrack::where('user_id', Auth::id())->latest()->firstOrFail();
        $track->update(['stopped_at_yard' => $data['stopped_at_yard'] ?? null]);

        return back()->with('success', $data['stopped_at_yard'] !== null
            ? 'Endstand gesetzt.'
            : 'Endstand zurückgenommen — die Uhr zählt wieder.');
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
