<?php

namespace App\Http\Controllers;

use App\Models\GarminConnection;
use App\Models\GarminWebhookEvent;
use App\Models\User;
use App\Services\Garmin\GarminClient;
use App\Services\Garmin\GarminDisconnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Die offizielle Garmin-Verbindung: verbinden, trennen, Benutzer-Webhook.
 *
 * Der Ablauf beim Verbinden: Zone3 erzeugt ein Geheimnis (`code_verifier`),
 * legt es unter einem `state` ab und schickt den Athleten mit dem
 * Fingerabdruck des Geheimnisses zu Garmin. Dort gibt er frei, was er teilen
 * will. Garmin schickt ihn mit einem Einmal-Code zurueck, und nur wer das
 * Geheimnis kennt, kann den Code gegen Tokens tauschen.
 *
 * Das Geheimnis liegt im Cache, nicht in der Session. Startet der Athlet in
 * der Homescreen-App, oeffnet iOS den Rueckweg von Garmin in Safari — mit
 * eigenem Cookie-Speicher, also ohne Session und ohne Anmeldung. Der Callback
 * erkennt den Athleten deshalb am `state` (zufaellig, zehn Minuten, einmal
 * benutzbar). Ist im Browser jemand anderes angemeldet, wird abgelehnt.
 */
class GarminController extends Controller
{
    private const CACHE_PREFIX = 'garmin-oauth:';
    private const PENDING_MINUTES = 10;

    public function __construct(
        private readonly GarminClient $client,
        private readonly GarminDisconnector $disconnector,
    ) {}

    public function connect(Request $request): Response
    {
        abort_unless($this->client->configured(), 503, 'Garmin ist nicht eingerichtet.');

        // Garmin verlangt eine ausdrueckliche Einwilligung in die KI-Auswertung
        // (/privacy Abschnitt 7). Ohne Haken keine Verbindung.
        $request->validate(['ai_consent' => 'accepted']);

        $state    = Str::random(40);
        $verifier = $this->client->makeVerifier();

        Cache::put(self::CACHE_PREFIX . $state, [
            'user_id'    => $request->user()->id,
            'verifier'   => $verifier,
            'consent_at' => now()->toIso8601String(),
        ], now()->addMinutes(self::PENDING_MINUTES));

        // Ein externer Redirect aus einem Inertia-Request braucht location().
        return Inertia::location($this->client->authorizationUrl($state, $verifier));
    }

    public function callback(Request $request): Response
    {
        $state   = (string) $request->query('state');
        $pending = $state !== '' ? Cache::pull(self::CACHE_PREFIX . $state) : null;

        if ($request->filled('error')) {
            return $this->finish($request, false, 'Garmin-Verbindung abgebrochen.');
        }

        if (! $pending) {
            return $this->finish($request, false, 'Garmin-Verbindung abgelaufen. Bitte noch einmal verbinden.');
        }

        // Wer hier angemeldet ist, muss der sein, der die Verbindung gestartet
        // hat. Sonst koennte ein fremder Verbinden-Link ein Garmin-Konto in
        // das Zone3-Konto eines anderen holen.
        if ($request->user() && $request->user()->id !== $pending['user_id']) {
            return $this->finish($request, false, 'Diese Garmin-Verbindung wurde von einem anderen Konto gestartet.');
        }

        $user = User::find($pending['user_id']);
        if (! $user || ! $request->filled('code')) {
            return $this->finish($request, false, 'Garmin hat die Verbindung nicht bestätigt. Bitte noch einmal verbinden.');
        }

        try {
            $token     = $this->client->exchangeCode((string) $request->query('code'), $pending['verifier']);
            $garminId  = $this->client->userId($token['access_token']);
            $permitted = $this->client->permissions($token['access_token']);
        } catch (Throwable $e) {
            Log::warning('Garmin-Verbindung fehlgeschlagen', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return $this->finish($request, false, 'Garmin hat die Verbindung nicht bestätigt. Bitte später noch einmal versuchen.');
        }

        // Ein Garmin-Konto gehoert genau einem Athleten. Sonst landeten die
        // Erholungswerte des einen beim anderen.
        $taken = GarminConnection::where('garmin_user_id', $garminId)->where('user_id', '!=', $user->id)->exists();
        if ($taken) {
            return $this->finish($request, false, 'Dieses Garmin-Konto ist schon mit einem anderen Zone3-Konto verbunden.');
        }

        GarminConnection::updateOrCreate(
            ['user_id' => $user->id],
            $this->client->tokenFields($token) + [
                'garmin_user_id'         => $garminId,
                'permissions'            => $permitted,
                'permissions_checked_at' => now(),
                'ai_consent_at'          => $pending['consent_at'],
                'last_refreshed_at'      => now(),
                'refresh_error'          => null,
                'refresh_error_at'       => null,
            ],
        );

        Log::info('Garmin verbunden', ['user_id' => $user->id, 'permissions' => $permitted]);

        return $this->finish($request, true, 'Garmin verbunden.');
    }

    /**
     * Wer angemeldet ist, landet wieder im Profil. Wer es nicht ist, kam in
     * einem anderen Browser zurueck als dem, in dem er gestartet hat (Safari
     * statt Homescreen-App) — dorthin fuehrt kein Weg, also eine Seite, die
     * sagt, was passiert ist und wohin es weitergeht.
     */
    private function finish(Request $request, bool $ok, string $message): Response
    {
        if ($request->user()) {
            return redirect()->route('profile.edit')
                ->with('garmin_status', ['type' => $ok ? 'success' : 'error', 'message' => $message]);
        }

        return Inertia::render('Garmin/Callback', ['ok' => $ok, 'message' => $message])->toResponse($request);
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $this->disconnector->disconnect($request->user());

        return redirect()->route('profile.edit')->with('garmin_status', ['type' => 'success', 'message' => 'Garmin getrennt.']);
    }

    /**
     * Deregistrierungen und Aenderungen der Freigaben, per Push.
     *
     * Garmin signiert seine Aufrufe nicht. Darum wird aus dem Body nur die
     * User-ID genommen und der Rest bei Garmin selbst nachgefragt: eine
     * Deregistrierung gilt erst, wenn Garmin den Token wirklich abweist, und
     * die Freigaben kommen aus `user/permissions`, nicht aus dem Aufruf.
     * So kann ein gefaelschter Aufruf weder trennen noch Rechte vortaeuschen.
     */
    public function webhookUser(Request $request): JsonResponse
    {
        foreach (['deregistrations', 'userPermissionsChange'] as $type) {
            foreach ((array) $request->input($type, []) as $item) {
                $this->handleUserEvent($type, (string) ($item['userId'] ?? ''));
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Erholungswerte per Push (Dailies, Sleeps, HRV, Stress, User Metrics).
     * Eine URL fuer alle Health-Typen; der Schluessel im Body sagt, was kam.
     *
     * Die Spezifikation verlangt 200 *vor* jeder Verarbeitung (30 s Timeout),
     * darum nur in die Queue. Garmin signiert nicht, und die Werte stehen im
     * Body selbst; geschuetzt ist der Weg nur dadurch, dass eine
     * Garmin-User-ID nirgends oeffentlich steht. Gespeichert wird nur fuer
     * verbundene Athleten mit Health-Freigabe.
     */
    public function webhookHealth(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        if (array_intersect(array_keys($payload), \App\Services\Garmin\GarminHealthImporter::TYPES) !== []) {
            \App\Jobs\ImportGarminHealthJob::dispatch($payload);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Aktivitaeten per Push (Zusammenfassung, Details mit Messpunkten,
     * in Garmin Connect geaenderte). Dieselbe Haltung wie bei den
     * Erholungswerten: 200 sofort, die Arbeit in der Queue, gespeichert nur
     * fuer verbundene Athleten mit Aktivitaets-Freigabe und Garmin als Quelle.
     */
    public function webhookActivities(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        if (array_intersect(array_keys($payload), \App\Services\Garmin\GarminActivityImporter::TYPES) !== []) {
            \App\Jobs\ImportGarminActivitiesJob::dispatch($payload);
        }

        return response()->json(['ok' => true]);
    }

    private function handleUserEvent(string $type, string $garminUserId): void
    {
        $connection = $garminUserId !== '' ? GarminConnection::where('garmin_user_id', $garminUserId)->first() : null;

        $event = GarminWebhookEvent::create([
            'type'           => $type,
            'garmin_user_id' => $garminUserId ?: null,
            'user_id'        => $connection?->user_id,
        ]);

        if (! $connection) {
            $event->update(['outcome' => GarminWebhookEvent::OUTCOME_UNKNOWN_USER]);
            return;
        }

        try {
            $response = $this->client->permissionsResponse($connection);
        } catch (\RuntimeException $e) {
            // Garmin hat die Erneuerung abgewiesen: der Zugang ist weg.
            $response = null;
        } catch (Throwable $e) {
            // Netz oder Garmin gestoert — daraus folgt nichts, schon gar
            // keine Trennung. Der naechste Aufruf oder der Tagesjob klaert es.
            $event->update(['outcome' => GarminWebhookEvent::OUTCOME_CHECK_FAILED, 'note' => Str::limit($e->getMessage(), 250)]);
            return;
        }

        $revoked = $response === null || in_array($response->status(), [401, 403], true);

        if ($revoked) {
            $this->disconnector->disconnect($connection->user, atGarmin: false);
            $event->update(['outcome' => GarminWebhookEvent::OUTCOME_DEREGISTERED]);
            Log::info('Garmin-Verbindung von Garmin aus getrennt', ['user_id' => $connection->user_id]);
            return;
        }

        if ($response->successful()) {
            $connection->update([
                'permissions'            => GarminConnection::normalizePermissions($response->json()),
                'permissions_checked_at' => now(),
            ]);
        }

        $event->update([
            'outcome' => $type === 'deregistrations'
                ? GarminWebhookEvent::OUTCOME_STILL_CONNECTED
                : GarminWebhookEvent::OUTCOME_PERMISSIONS,
        ]);
    }
}
