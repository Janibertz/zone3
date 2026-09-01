<?php

namespace App\Http\Controllers;

use App\Jobs\ImportStravaActivityJob;
use App\Models\Activity;
use App\Models\StravaAccount;
use App\Services\BestEffortService;
use App\Services\StravaImportService;
use App\Services\StravaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class StravaController extends Controller
{
    public function __construct(private readonly StravaImportService $importer) {}

    public function connect(StravaService $strava): RedirectResponse
    {
        return redirect()->away($strava->getAuthorizationUrl());
    }

    public function callback(Request $request, StravaService $strava): RedirectResponse
    {
        $request->validate(['code' => 'required|string']);

        $tokenData = $strava->exchangeCodeForToken($request->code);
        $user = $request->user();

        $user->stravaAccount()->updateOrCreate(
            ['strava_id' => $tokenData['athlete']['id']],
            [
                'username'         => $tokenData['athlete']['username'] ?? null,
                'access_token'     => $tokenData['access_token'],
                'refresh_token'    => $tokenData['refresh_token'],
                'token_expires_at' => Carbon::createFromTimestamp($tokenData['expires_at']),
                'scope'            => isset($tokenData['scope']) ? explode(',', $tokenData['scope']) : null,
            ]
        );

        return redirect()->route('dashboard')->with('success', 'Strava erfolgreich verbunden.');
    }

    /**
     * Disconnect Strava — removes the account record and all imported activities.
     */
    public function disconnect(Request $request): RedirectResponse
    {
        $user = $request->user();

        $user->stravaAccount?->delete();
        $user->activities()->delete();

        return redirect()->route('profile.edit')
            ->with('status', 'strava-disconnected');
    }

    /**
     * Manual sync: saves activities immediately, dispatches AI job if rate limit allows.
     */
    public function sync(Request $request, StravaService $strava, BestEffortService $bestEfforts): RedirectResponse
    {
        $user    = $request->user();
        $account = $user->stravaAccount;

        if (! $account) {
            return redirect()->route('dashboard')->with('error', 'Strava nicht verbunden.');
        }

        $activities = $strava->fetchRecentActivities($account);

        $newCount      = 0;
        $newRunCount   = 0;
        $lapBackfilled = 0;

        // Was der Athlet geloescht hat, bleibt geloescht. Ohne diese Sperre
        // legt updateOrCreate es beim naechsten Abgleich wieder an.
        $ignored = array_flip(\App\Models\IgnoredStravaActivity::idsFor($user->id));

        foreach ($activities as $activityData) {
            if (isset($ignored[$activityData['id']])) {
                continue;
            }

            $isNew = ! Activity::where('strava_id', $activityData['id'])
                ->where('user_id', $user->id)
                ->exists();

            $activity = Activity::updateOrCreate(
                ['strava_id' => $activityData['id'], 'user_id' => $user->id],
                [
                    'name'                 => $activityData['name'] ?? 'Aktivität',
                    'description'          => $activityData['description'] ?? null,
                    'type'                 => $activityData['type'] ?? 'Run',
                    'distance'             => $activityData['distance'] ?? 0,
                    'moving_time'          => $activityData['moving_time'] ?? 0,
                    'elapsed_time'         => $activityData['elapsed_time'] ?? 0,
                    'total_elevation_gain' => $activityData['total_elevation_gain'] ?? 0,
                    'average_speed'        => $activityData['average_speed'] ?? 0,
                    'average_watts'        => $activityData['average_watts'] ?? null,
                    'max_speed'            => $activityData['max_speed'] ?? 0,
                    'average_heartrate'    => $activityData['average_heartrate'] ?? null,
                    'max_heartrate'        => $activityData['max_heartrate'] ?? null,
                    'start_date'           => $activityData['start_date'] ?? now(),
                    'location_city'        => $activityData['location_city'] ?? null,
                    'location_state'       => $activityData['location_state'] ?? null,
                    'location_country'     => $activityData['location_country'] ?? null,
                    'polyline'             => $this->importer->extractPolyline($activityData),
                ]
            );

            if ($isNew) {
                $newCount++;
                // One detail call yields both laps and best_efforts (the activity
                // list endpoint carries neither).
                $detail = $strava->fetchActivity($account, (int) $activityData['id']);
                if ($detail) {
                    $this->importer->applyStartCoords($activity, $detail);
                    if (! empty($detail['laps'])) {
                        $activity->laps = $this->importer->normalizeLaps($detail['laps']);
                        $activity->save();
                    }
                    if ($activity->type === 'Run') {
                        $newRunCount++;
                        $newRecords = $bestEfforts->syncFromActivityData($activity, $detail);
                        if (! empty($newRecords)) {
                            $this->importer->flagPendingPr($user->id, $activity->id);
                        }
                    } else {
                        // Mark non-runs as processed so the backfill skips them.
                        $activity->forceFill(['best_efforts_synced_at' => now()])->save();
                    }
                }
            } elseif ($activity->best_efforts_synced_at === null && $lapBackfilled < 10) {
                // Backfill laps + best efforts for activities imported before this feature.
                // No celebration for these historical runs.
                $detail = $strava->fetchActivity($account, (int) $activityData['id']);
                if ($detail) {
                    $this->importer->applyStartCoords($activity, $detail);
                    if ($activity->laps === null) {
                        $activity->laps = ! empty($detail['laps'])
                            ? $this->importer->normalizeLaps($detail['laps'])
                            : [];
                        $activity->save();
                    }
                    if ($activity->type === 'Run') {
                        $bestEfforts->syncFromActivityData($activity, $detail);
                    } else {
                        $activity->forceFill(['best_efforts_synced_at' => now()])->save();
                    }
                }
                $lapBackfilled++;
            }

            // Match to plan sessions or create unplanned entry for all activity types
            $this->importer->matchActivityToSession($user->id, $activity);
        }

        $this->importer->dispatchCalculationIfDue($user->id, $newRunCount);
        $this->importer->dispatchPlanRegenerationIfNeeded($user->id);

        if ($newCount === 0) {
            $message = 'Keine neuen Aktivitäten gefunden.';
        } else {
            $message = "{$newCount} neue " . ($newCount === 1 ? 'Aktivität' : 'Aktivitäten') . " importiert.";
        }

        $profile = $user->fresh()->runnerProfile;
        if ($profile?->threshold_pace_calculating) {
            $message .= ' Schwellenpace wird im Hintergrund neu berechnet.';
        }

        return redirect()->route('dashboard')->with('sync_result', $message);
    }

    /**
     * Strava webhook verification (GET).
     */
    public function webhookVerify(Request $request): \Illuminate\Http\JsonResponse|Response
    {
        if (! $this->callbackTokenMatches($request)) {
            return response('Unauthorized', 401);
        }

        $verifyToken = config('services.strava.webhook_verify_token', 'zone3_webhook');

        if (
            $request->get('hub_mode') === 'subscribe' &&
            $request->get('hub_verify_token') === $verifyToken
        ) {
            return response()->json(['hub.challenge' => $request->get('hub_challenge')]);
        }

        return response('Unauthorized', 401);
    }

    /**
     * Strava-Webhook (POST) — ein neues Ereignis von Strava.
     *
     * Der Handler nimmt es an und gibt es weiter. Der Import selbst lief
     * frueher hier drin: Aktivitaet bei Strava abholen, zuordnen,
     * Bestzeiten schreiben, Push verschicken — alles im Request, auf einem
     * einthreadigen Webserver. Wer Zone3 in diesen Sekunden oeffnete,
     * wartete mit. Und weil Strava dasselbe Ereignis erneut zustellt, wenn
     * die Antwort ausbleibt, machte die Langsamkeit sich selbst schlimmer.
     *
     * Strava signiert seine Webhooks nicht — es gibt hier keine Signatur zu
     * pruefen, anders als bei GitHub. Der Schutz liegt woanders: aus dem
     * Rumpf werden nur zwei Zahlen genommen, und die Aktivitaet wird
     * anschliessend ueber die API mit dem Token des Kontos GEHOLT. Ein
     * gefaelschter Aufruf kann damit keine erfundenen Daten einschleusen.
     * Was er koennte, ist Arbeit ausloesen; dagegen steht das Throttle auf
     * der Route.
     */
    public function webhook(Request $request): Response
    {
        if (! $this->callbackTokenMatches($request)) {
            return response('Unauthorized', 401);
        }

        $data = $request->all();

        if (
            ($data['object_type'] ?? '') !== 'activity' ||
            ($data['aspect_type'] ?? '') !== 'create'
        ) {
            return response('OK');
        }

        $account = StravaAccount::where('strava_id', $data['owner_id'] ?? null)->first();
        if (! $account) {
            return response('OK');
        }

        ImportStravaActivityJob::dispatch($account->id, (int) $data['object_id']);

        return response('OK');
    }

    /**
     * Traegt der Aufruf das Token aus der registrierten Callback-URL?
     *
     * Strava signiert seine Webhooks nicht. Das einzige Geheimnis, das sich
     * mitgeben laesst, steht in der URL, die man bei der Anmeldung
     * hinterlegt — Strava ruft genau diese auf, Query-String eingeschlossen.
     *
     * Deshalb wird hier nichts konfiguriert und nichts zusaetzlich gesetzt:
     * die Pruefung liest das Token aus `webhook_callback_url`. Steht dort
     * keins — der heutige Stand —, gibt es nichts zu pruefen und die Methode
     * sagt ja. Wer sie scharf stellen will, haengt `?token=…` an
     * `STRAVA_WEBHOOK_CALLBACK_URL` und meldet den Webhook neu an; ab dann
     * greift sie von selbst, auch im Handshake. Man kann sich damit nicht
     * aussperren, denn beide Seiten lesen dieselbe URL.
     */
    private function callbackTokenMatches(Request $request): bool
    {
        $callbackUrl = (string) config('services.strava.webhook_callback_url');

        parse_str((string) parse_url($callbackUrl, PHP_URL_QUERY), $params);
        $expected = $params['token'] ?? null;

        if (! $expected) {
            return true;
        }

        return hash_equals((string) $expected, (string) $request->query('token', ''));
    }
}
