<?php

namespace App\Http\Controllers;

use App\Models\GarminConnection;
use App\Models\GarminWebhookEvent;
use App\Services\Garmin\GarminClient;
use App\Services\Garmin\GarminDisconnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Die offizielle Garmin-Verbindung: verbinden, trennen, Benutzer-Webhook.
 *
 * Der Ablauf beim Verbinden: Zone3 erzeugt ein Geheimnis (`code_verifier`),
 * legt es mit einem `state` in die Session und schickt den Athleten mit dem
 * Fingerabdruck des Geheimnisses zu Garmin. Dort gibt er frei, was er teilen
 * will. Garmin schickt ihn mit einem Einmal-Code zurueck, und nur wer das
 * Geheimnis kennt, kann den Code gegen Tokens tauschen.
 */
class GarminController extends Controller
{
    private const SESSION_KEY = 'garmin_oauth';

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

        $request->session()->put(self::SESSION_KEY, [
            'state'      => $state,
            'verifier'   => $verifier,
            'consent_at' => now()->toIso8601String(),
        ]);

        // Ein externer Redirect aus einem Inertia-Request braucht location().
        return \Inertia\Inertia::location($this->client->authorizationUrl($state, $verifier));
    }

    public function callback(Request $request): RedirectResponse
    {
        $pending = $request->session()->pull(self::SESSION_KEY);
        $back    = redirect()->route('profile.edit');

        if ($request->filled('error')) {
            return $back->with('garmin_status', ['type' => 'error', 'message' => 'Garmin-Verbindung abgebrochen.']);
        }

        if (! $pending || ! hash_equals($pending['state'], (string) $request->query('state'))) {
            return $back->with('garmin_status', ['type' => 'error', 'message' => 'Garmin-Verbindung abgelaufen. Bitte noch einmal verbinden.']);
        }

        $request->validate(['code' => 'required|string']);

        try {
            $token     = $this->client->exchangeCode($request->query('code'), $pending['verifier']);
            $garminId  = $this->client->userId($token['access_token']);
            $permitted = $this->client->permissions($token['access_token']);
        } catch (Throwable $e) {
            Log::warning('Garmin-Verbindung fehlgeschlagen', ['user_id' => $request->user()->id, 'error' => $e->getMessage()]);

            return $back->with('garmin_status', ['type' => 'error', 'message' => 'Garmin hat die Verbindung nicht bestätigt. Bitte später noch einmal versuchen.']);
        }

        $user = $request->user();

        // Ein Garmin-Konto gehoert genau einem Athleten. Sonst landeten die
        // Erholungswerte des einen beim anderen.
        $taken = GarminConnection::where('garmin_user_id', $garminId)->where('user_id', '!=', $user->id)->exists();
        if ($taken) {
            return $back->with('garmin_status', ['type' => 'error', 'message' => 'Dieses Garmin-Konto ist schon mit einem anderen Zone3-Konto verbunden.']);
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

        return $back->with('garmin_status', ['type' => 'success', 'message' => 'Garmin verbunden.']);
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
                'permissions'            => array_values((array) $response->json()),
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
