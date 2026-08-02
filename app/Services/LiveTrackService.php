<?php

namespace App\Services;

use App\Models\LiveTrack;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Holt Garmins LiveTrack-Daten und verdichtet sie zu einem kleinen Stand,
 * den die öffentliche Seite anzeigt.
 *
 * Wichtig: Garmin bietet für LiveTrack keine offizielle API. Die beiden
 * Endpunkte hier sind die, die Garmins eigene Weboberfläche benutzt. Sie
 * können sich ohne Ankündigung ändern — deshalb wird jeder Fehler
 * abgefangen und in `last_error` festgehalten, statt die Seite zu kippen.
 * Die Yard-Uhr rechnet ohnehin allein aus der Startzeit und läuft weiter,
 * wenn hier nichts mehr ankommt.
 */
class LiveTrackService
{
    /**
     * Stand 02.08.2026. Der frühere REST-Baum unter /services ist ersatzlos
     * verschwunden — LiveTrack läuft jetzt als Next.js-App. Der Endpunkt
     * hier ist der, den deren Oberfläche benutzt.
     */
    private const BASE = 'https://livetrack.garmin.com/api';

    /** Messreihe auf eine Minute verdünnen — 4-Sekunden-Punkte braucht niemand. */
    private const SERIES_STEP_SECONDS = 60;

    /** Deckel, damit die Reihe auch nach 40 Stunden handlich bleibt. */
    private const SERIES_MAX = 2600;

    public function poll(LiveTrack $track): bool
    {
        if (! $track->hasLiveTrack()) {
            return false;
        }

        try {
            $points = $this->fetchTrackPoints($track);
        } catch (\Throwable $e) {
            $track->forceFill([
                'last_polled_at' => now(),
                'last_error'     => 'Abruf fehlgeschlagen: ' . $e->getMessage(),
            ])->save();

            Log::warning('LiveTrack-Abruf fehlgeschlagen', ['track' => $track->id, 'error' => $e->getMessage()]);
            return false;
        }

        $series = $track->series ?? [];
        $state  = $track->state ?? [];

        foreach ($points as $p) {
            $point = $this->normalizePoint($p);
            if (! $point) continue;

            $state = $this->mergePoint($state, $point);
            $series = $this->appendToSeries($series, $point);
        }

        $track->forceFill([
            'state'          => $state,
            'series'         => array_slice($series, -self::SERIES_MAX),
            'last_polled_at' => now(),
            'last_error'     => null,
        ])->save();

        return true;
    }

    /**
     * Neue Punkte seit dem zuletzt gesehenen Zeitstempel.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchTrackPoints(LiveTrack $track): array
    {
        $from = data_get($track->state, 'lastPointMs');

        // Token als Query-Parameter, Startzeitpunkt als ISO-Zeitstempel.
        $query = ['token' => $track->garmin_token];
        if ($from) {
            // +1 ms, sonst kommt der letzte Punkt jedes Mal erneut.
            $query['begin'] = \Carbon\Carbon::createFromTimestampMs(((int) $from) + 1)
                ->toIso8601ZuluString('millisecond');
        }

        $url = sprintf('%s/sessions/%s/track-points/common', self::BASE, $track->garmin_session_id);

        $response = Http::timeout(12)
            ->withHeaders([
                'Accept'     => 'application/json, text/plain, */*',
                'Referer'    => $track->liveTrackUrl(),
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            ])
            ->get($url, $query);

        if ($response->status() === 403) {
            // Kommt auch bei falschem Token — die Sperre greift davor.
            // Es liegt also nicht am Link, sondern an Garmins Botabwehr.
            throw new \RuntimeException(
                'Garmin blockt den Abruf vom Server (403). Das liegt nicht an deinem Link — '
                . 'Cloudflare lässt dort nur echte Browser durch.'
            );
        }

        if ($response->status() === 404) {
            throw new \RuntimeException('Sitzung nicht gefunden — Link abgelaufen oder falsch.');
        }

        if (! $response->successful()) {
            throw new \RuntimeException('HTTP ' . $response->status());
        }

        $body = $response->json();

        // Je nach Antwort mal ein Objekt mit trackPoints, mal eine nackte Liste.
        return data_get($body, 'trackPoints') ?? (is_array($body) ? $body : []);
    }

    /**
     * Ein Trackpoint auf die Felder eindampfen, die wir zeigen. Garmin
     * benennt die Felder je nach Gerät und Version unterschiedlich, deshalb
     * werden mehrere Schreibweisen probiert.
     *
     * @return array<string, mixed>|null
     */
    private function normalizePoint(array $p): ?array
    {
        $timestamp = data_get($p, 'dateTime')
            ?? data_get($p, 'timestamp')
            ?? data_get($p, 'time');

        if (! $timestamp) return null;

        $ms = is_numeric($timestamp)
            ? (int) $timestamp
            : (int) (strtotime((string) $timestamp) * 1000);

        if ($ms <= 0) return null;

        $fit = data_get($p, 'fitnessPointData', []);

        $first = fn (array $keys) => collect($keys)
            ->map(fn ($k) => data_get($fit, $k) ?? data_get($p, $k))
            ->first(fn ($v) => $v !== null && $v !== '');

        return [
            'ms'    => $ms,
            'lat'   => $this->float(data_get($p, 'position.lat') ?? data_get($p, 'latitude')),
            'lon'   => $this->float(data_get($p, 'position.lon') ?? data_get($p, 'longitude')),
            'hr'    => $this->int($first(['heartRateBeatsPerMin', 'heartRate'])),
            'speed' => $this->float($first(['speedMetersPerSecond', 'speed'])),
            'dist'  => $this->float($first(['totalDistanceMeters', 'distanceMeters', 'TOTAL_DISTANCE'])),
            'alt'   => $this->float($first(['elevationMeters', 'altitude', 'ELEVATION'])),
            'dur'   => $this->int($first(['durationSecs', 'duration', 'DURATION'])),
        ];
    }

    /** Letzten Stand fortschreiben — Höchstwerte gewinnen, damit Ausreißer nichts löschen. */
    private function mergePoint(array $state, array $point): array
    {
        if (($state['lastPointMs'] ?? 0) > $point['ms']) {
            return $state;
        }

        $state['lastPointMs'] = $point['ms'];

        foreach (['lat', 'lon', 'hr', 'speed', 'alt'] as $key) {
            if ($point[$key] !== null) $state[$key] = $point[$key];
        }

        // Distanz und Dauer laufen monoton — nie zurückspringen lassen.
        if ($point['dist'] !== null) {
            $state['distanceM'] = max($state['distanceM'] ?? 0, $point['dist']);
        }
        if ($point['dur'] !== null) {
            $state['durationSec'] = max($state['durationSec'] ?? 0, $point['dur']);
        }

        return $state;
    }

    /** Höchstens ein Eintrag pro Minute. */
    private function appendToSeries(array $series, array $point): array
    {
        $bucket = intdiv((int) ($point['ms'] / 1000), self::SERIES_STEP_SECONDS) * self::SERIES_STEP_SECONDS;
        $lastBucket = $series ? ($series[count($series) - 1]['t'] ?? null) : null;

        $entry = [
            't'  => $bucket,
            'hr' => $point['hr'],
            // Pace in Sekunden pro Kilometer — nur bei plausibler Geschwindigkeit.
            'p'  => ($point['speed'] && $point['speed'] > 0.5) ? (int) round(1000 / $point['speed']) : null,
            'km' => $point['dist'] !== null ? round($point['dist'] / 1000, 2) : null,
            // Position fuer die eigene Karte. Fuenf Nachkommastellen sind
            // rund ein Meter genau und halten die Reihe klein.
            'la' => $point['lat'] !== null ? round($point['lat'], 5) : null,
            'lo' => $point['lon'] !== null ? round($point['lon'], 5) : null,
        ];

        if ($bucket === $lastBucket) {
            $series[count($series) - 1] = $entry;   // Minute fortschreiben
        } else {
            $series[] = $entry;
        }

        return $series;
    }

    private function float($v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private function int($v): ?int
    {
        return is_numeric($v) ? (int) round((float) $v) : null;
    }
}
