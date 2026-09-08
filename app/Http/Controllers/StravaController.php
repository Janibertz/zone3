<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateSessionReviewJob;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Support\Facades\Log;
use App\Models\Activity;
use App\Models\StravaAccount;
use App\Models\StravaWebhookEvent;
use App\Services\ActivityDeletionService;
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
     * Eine bei Strava geloeschte Aktivitaet auch hier entfernen.
     *
     * Ueber `ActivityDeletionService`, nie mit einem blossen `delete()`:
     * eine abgehakte Einheit haengt daran und stuende sonst weiter auf
     * „abgeschlossen" — mit den gelaufenen Zahlen, aber ohne Beleg. Eine
     * geplante wird aus dem Schnappschuss wiederhergestellt, eine
     * ungeplante verschwindet mit.
     *
     * Der Grabstein ist hier streng genommen ueberfluessig — was bei Strava
     * geloescht ist, liefert auch der Abgleich nicht mehr. Er kostet nichts
     * und macht den Weg identisch zu dem, den der Athlet selbst ausloest.
     */
    private function webhookDelete(
        StravaWebhookEvent $event,
        ?StravaAccount $account,
        int $stravaId,
    ): Response {
        if (! $account || ! $stravaId) {
            $event->update(['outcome' => StravaWebhookEvent::OUTCOME_UNKNOWN_OWNER]);

            return response('OK');
        }

        $activity = Activity::where('user_id', $account->user_id)
            ->where('strava_id', $stravaId)
            ->first();

        if (! $activity) {
            $event->update([
                'user_id' => $account->user_id,
                'outcome' => StravaWebhookEvent::OUTCOME_DELETE_UNKNOWN,
            ]);

            return response('OK');
        }

        $name   = $activity->name;
        $result = app(ActivityDeletionService::class)->delete($activity);

        Log::info('Strava-Aktivitaet bei Strava geloescht, hier entfernt', [
            'user_id'   => $account->user_id,
            'strava_id' => $stravaId,
            'name'      => $name,
        ] + $result);

        $event->update([
            'user_id' => $account->user_id,
            'outcome' => StravaWebhookEvent::OUTCOME_DELETED,
            'note'    => $name,
        ]);

        return response('OK');
    }

    /**
     * Strava webhook verification (GET).
     */
    public function webhookVerify(Request $request): \Illuminate\Http\JsonResponse|Response
    {
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
     * Der Import laeuft wieder HIER, im Request, wie vor dem Umbau.
     *
     * Der Umbau in einen Job war technisch gut begruendet — der Webserver
     * ist einthreadig, und ein Import mit zwei HTTP-Aufrufen blockiert ihn.
     * Nur: danach kamen keine Aktivitaeten mehr an, und nach zwei Anlaeufen
     * (eigene Queue, eigener Worker) kamen sie immer noch nicht. Ein
     * Kernfeature, das nicht funktioniert, wiegt schwerer als ein Request,
     * der ein paar Sekunden dauert. Also zurueck auf den Stand, der lief.
     *
     * Was NICHT zurueckgebaut ist: die Import- und Zuordnungslogik liegt
     * weiterhin in `StravaImportService`. Sie ist dieselbe wie vorher, nur
     * an einer Stelle statt in zwei Kopien — daran lag nichts.
     *
     * Ebenfalls neu und bewusst geblieben: die Logzeile ganz oben. Sie ist
     * die einzige Moeglichkeit, die Frage "ruft Strava ueberhaupt an?" ohne
     * Raten zu beantworten. Genau die hat bei der Suche gefehlt.
     */
    public function webhook(
        Request $request,
        StravaService $strava,
        WebPushService $webPush,
        BestEffortService $bestEfforts,
    ): Response {
        $data = $request->all();

        // Vor jeder Filterung: dass ueberhaupt jemand angeklopft hat, ist
        // die erste Information, die man bei einer Stoerung braucht.
        // In die DATENBANK, nicht nur ins Log: Produktion schreibt in den
        // Container-Stream, nicht in eine Datei. Die Frage „ruft Strava
        // ueberhaupt an?" war deshalb tagelang nicht zu beantworten.
        $event = StravaWebhookEvent::create([
            'object_type' => $data['object_type'] ?? null,
            'aspect_type' => $data['aspect_type'] ?? null,
            'owner_id'    => $data['owner_id'] ?? null,
            'object_id'   => $data['object_id'] ?? null,
        ]);

        Log::info('Strava-Webhook empfangen', $event->only(
            'object_type', 'aspect_type', 'owner_id', 'object_id',
        ));

        if (($data['object_type'] ?? '') !== 'activity') {
            $event->update(['outcome' => StravaWebhookEvent::OUTCOME_WRONG_TYPE]);

            return response('OK');
        }

        $account = StravaAccount::where('strava_id', $data['owner_id'] ?? null)->first();

        // Was der Athlet bei Strava loescht, soll auch hier verschwinden.
        // Vorher blieb es stehen: der Handler kannte nur `create`, und die
        // Aktivitaet zaehlte weiter in Wochenumfang, Belastung und
        // Schwellenpace — fuer einen Lauf, den es nicht mehr gibt.
        if (($data['aspect_type'] ?? '') === 'delete') {
            return $this->webhookDelete($event, $account, (int) ($data['object_id'] ?? 0));
        }

        if (($data['aspect_type'] ?? '') !== 'create') {
            $event->update(['outcome' => StravaWebhookEvent::OUTCOME_WRONG_TYPE]);

            return response('OK');
        }

        if (! $account) {
            Log::warning('Strava-Webhook: kein Konto zu dieser owner_id', [
                'owner_id' => $data['owner_id'] ?? null,
            ]);

            $event->update(['outcome' => StravaWebhookEvent::OUTCOME_UNKNOWN_OWNER]);

            return response('OK');
        }

        $activityData = $strava->fetchActivity($account, (int) $data['object_id']);

        if (! $activityData) {
            Log::warning('Strava-Webhook: Aktivitaet nicht abrufbar', [
                'user_id'   => $account->user_id,
                'object_id' => $data['object_id'] ?? null,
            ]);

            $event->update([
                'user_id' => $account->user_id,
                'outcome' => StravaWebhookEvent::OUTCOME_NOT_FETCHABLE,
            ]);

            return response('OK');
        }

        $userId   = $account->user_id;
        $activity = $this->importer->importFromDetail($userId, $activityData);

        // Der Athlet hat sie in Zone3 geloescht — der Grabstein haelt sie
        // draussen.
        if (! $activity) {
            $event->update([
                'user_id' => $userId,
                'outcome' => StravaWebhookEvent::OUTCOME_TOMBSTONED,
            ]);

            return response('OK');
        }

        $isRun = $activity->type === 'Run';

        $this->importer->dispatchCalculationIfDue($userId, $isRun ? 1 : 0);
        $this->importer->matchActivityToSession($userId, $activity);
        $this->importer->dispatchPlanRegenerationIfNeeded($userId);

        // Ein Review fuer jede Einheit, die diese Aktivitaet abgeschlossen hat.
        TrainingSession::where('user_id', $userId)
            ->where('activity_id', $activity->id)
            ->where('status', 'completed')
            ->whereNull('reviewed_at')
            ->pluck('id')
            ->each(fn ($id) => GenerateSessionReviewJob::dispatch($id)->delay(now()->addSeconds(20)));

        if ($isRun) {
            // Die Detailantwort traegt `best_efforts` — die Aktivitaetsliste nicht.
            $newRecords = $bestEfforts->syncFromActivityData($activity, $activityData);
            if (! empty($newRecords)) {
                $this->importer->flagPendingPr($userId, $activity->id);
            }
        }

        $user = User::find($userId);
        if ($user && $user->push_notifications_enabled) {
            $distKm = $activity->distance > 0 ? round($activity->distance / 1000, 1) . ' km' : '';
            $body   = trim($activity->name . ($distKm ? " · {$distKm}" : ''));

            $webPush->sendToUser($user, 'Neue Aktivität importiert 🏃', $body, '/activities');
        }

        Log::info('Strava-Aktivitaet importiert', [
            'user_id'   => $userId,
            'strava_id' => $activity->strava_id,
            'type'      => $activity->type,
            'name'      => $activity->name,
        ]);

        $event->update([
            'user_id' => $userId,
            'outcome' => StravaWebhookEvent::OUTCOME_IMPORTED,
            'note'    => $activity->name,
        ]);

        return response('OK');
    }

}
