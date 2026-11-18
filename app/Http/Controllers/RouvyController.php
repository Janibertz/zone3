<?php

namespace App\Http\Controllers;

use App\Jobs\ImportRouvyActivityJob;
use App\Jobs\SyncRouvyActivitiesJob;
use App\Jobs\SyncRouvyWorkoutsJob;
use App\Models\RouvyConnection;
use App\Models\RouvyScheduledWorkout;
use App\Models\RouvyWebhookEvent;
use App\Models\User;
use App\Services\Rouvy\RouvyClient;
use App\Services\Rouvy\RouvyDisconnector;
use App\Services\Rouvy\RouvyWebhookSignature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Die ROUVY-Verbindung: verbinden, Rückweg, trennen, Webhook (18.11.2026).
 *
 * Radeinheiten gehen als Workout mit Datum in die ROUVY-Bibliothek, Fahrten
 * kommen zurück. Derselbe Ablauf wie bei intervals.icu: `state` im Cache,
 * Rückweg ohne Anmeldung (iOS öffnet ihn aus der Homescreen-App in Safari).
 * **Ohne Einwilligung keine Verbindung** — ROUVY erlaubt KI auf seinen
 * Fahrten nur mit ausdrücklicher Einwilligung des Athleten.
 */
class RouvyController extends Controller
{
    private const CACHE_PREFIX = 'rouvy-oauth:';
    private const PENDING_MINUTES = 10;

    public function __construct(
        private readonly RouvyClient $client,
        private readonly RouvyDisconnector $disconnector,
    ) {}

    public function connect(Request $request): Response
    {
        abort_unless($this->client->configured(), 404);
        $request->validate(['ai_consent' => 'accepted'], ['ai_consent.accepted' => 'Ohne Einwilligung kann Zone3 keine Fahrten von ROUVY übernehmen.']);

        OnboardingController::rememberConnectStep($request);

        $state = Str::random(40);
        Cache::put(self::CACHE_PREFIX . $state, ['user_id' => $request->user()->id], now()->addMinutes(self::PENDING_MINUTES));

        return Inertia::location($this->client->authorizationUrl($state));
    }

    /** ROUVY nennt den Ausgang `response=success|error`, bei Fehler mit `reason`. */
    public function callback(Request $request): Response
    {
        $state   = (string) $request->query('state');
        $pending = $state !== '' ? Cache::pull(self::CACHE_PREFIX . $state) : null;

        if ($request->query('response') === 'error' || $request->filled('error')) {
            Log::info('ROUVY-Verbindung abgebrochen', ['reason' => Str::limit((string) $request->query('reason', $request->query('error')), 100)]);

            return $this->finish($request, false, 'ROUVY-Verbindung abgebrochen.');
        }
        if (! $pending) {
            return $this->finish($request, false, 'ROUVY-Verbindung abgelaufen. Bitte noch einmal verbinden.');
        }
        if ($request->user() && $request->user()->id !== $pending['user_id']) {
            return $this->finish($request, false, 'Diese ROUVY-Verbindung wurde von einem anderen Konto gestartet.');
        }

        $user = User::find($pending['user_id']);
        if (! $user || ! $request->filled('code')) {
            return $this->finish($request, false, 'ROUVY hat die Verbindung nicht bestätigt. Bitte noch einmal verbinden.');
        }

        try {
            $token   = $this->client->exchangeCode((string) $request->query('code'));
            $rouvyId = $this->client->userId($token['access_token']);
        } catch (Throwable $e) {
            Log::warning('ROUVY-Verbindung fehlgeschlagen', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return $this->finish($request, false, 'ROUVY hat die Verbindung nicht bestätigt. Bitte später noch einmal versuchen.');
        }

        // Ein ROUVY-Konto gehört genau einem Athleten.
        if (RouvyConnection::where('rouvy_user_id', $rouvyId)->where('user_id', '!=', $user->id)->exists()) {
            return $this->finish($request, false, 'Dieses ROUVY-Konto ist schon mit einem anderen Zone3-Konto verbunden.');
        }

        RouvyConnection::updateOrCreate(['user_id' => $user->id], RouvyClient::tokenFields($token) + [
            'rouvy_user_id'        => $rouvyId,
            'scopes'               => $token['scope'] ?? RouvyClient::SCOPES,
            'ai_consent_at'        => now(),
            'activities_synced_at' => null,
            'last_error'           => null,
            'last_error_at'        => null,
        ]);
        // Ein neues Konto kennt unsere Workouts nicht: alles einmal neu senden.
        RouvyScheduledWorkout::where('user_id', $user->id)->delete();

        Log::info('ROUVY verbunden', ['user_id' => $user->id]);
        SyncRouvyWorkoutsJob::dispatch($user->id);
        SyncRouvyActivitiesJob::dispatch($user->id);

        return $this->finish($request, true, 'ROUVY verbunden.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $this->disconnector->disconnect($request->user());

        return redirect()->route('profile.edit')
            ->with('rouvy_status', ['type' => 'success', 'message' => 'ROUVY getrennt. Die Radeinheiten sind aus ROUVY genommen.']);
    }

    /**
     * Body `{type, clientId, ownerId, payload}`, signiert nach Standard
     * Webhooks (`RouvyWebhookSignature`). Wiederholungen tragen dieselbe
     * `webhook-id`; eine schon gesehene wird mit 200 quittiert und nicht
     * noch einmal verarbeitet.
     */
    public function webhook(Request $request, RouvyWebhookSignature $signature): Response
    {
        $id = (string) $request->header('webhook-id');

        $valid = $signature->verify(
            $id,
            (string) $request->header('webhook-timestamp'),
            (string) $request->header('webhook-signature'),
            $request->getContent(),
        );
        if (! $valid) {
            RouvyWebhookEvent::create(['type' => 'unknown', 'outcome' => 'bad_signature']);
            Log::warning('ROUVY-Webhook: Signatur passt nicht');

            return response()->noContent(401);
        }

        // Der Schlüssel gilt für alle ROUVY-Apps; nur was für Zone3 bestimmt ist, zählt.
        if ((string) $request->input('clientId') !== (string) config('services.rouvy.client_id')) {
            RouvyWebhookEvent::create(['type' => 'unknown', 'outcome' => 'other_client']);

            return response()->noContent(200);
        }

        if (RouvyWebhookEvent::where('webhook_id', $id)->exists()) {
            return response()->noContent(200);
        }

        $type    = (string) $request->input('type', 'unknown');
        $owner   = (string) $request->input('ownerId', '');
        $payload = $request->input('payload');
        $payload = is_array($payload) ? $payload : [];

        $record = RouvyWebhookEvent::create([
            'webhook_id' => Str::limit($id, 64, ''),
            'type'       => Str::limit($type, 40, ''),
            'owner_id'   => $owner !== '' ? Str::limit($owner, 64, '') : null,
            'entity_id'  => isset($payload['activityId']) ? Str::limit((string) $payload['activityId'], 64, '') : null,
            'outcome'    => 'received',
        ]);

        $connection = $owner !== '' ? RouvyConnection::where('rouvy_user_id', $owner)->first() : null;
        if (! $connection) {
            $record->update(['outcome' => 'unknown_user']);

            return response()->noContent(200);
        }

        $outcome = match ($type) {
            'activity'   => $this->queueActivity($connection, $payload, $record->id),
            'disconnect' => $this->remoteDisconnect($connection),
            default      => 'ignored',
        };

        $record->update(['outcome' => $outcome]);

        return response()->noContent(200);
    }

    private function queueActivity(RouvyConnection $connection, array $payload, int $eventId): string
    {
        if (($payload['activityId'] ?? '') === '') {
            return 'no_activity';
        }

        ImportRouvyActivityJob::dispatch($connection->user_id, $payload, $eventId);

        return 'queued';
    }

    /** Im ROUVY Riders Portal getrennt: hier nur noch aufräumen. */
    private function remoteDisconnect(RouvyConnection $connection): string
    {
        $user = $connection->user;
        if ($user) {
            $this->disconnector->disconnect($user, remote: true);
            Log::info('ROUVY: Athlet hat bei ROUVY getrennt', ['user_id' => $user->id]);
        }

        return 'disconnected';
    }

    private function finish(Request $request, bool $ok, string $message): Response
    {
        if ($request->user()) {
            return redirect()->route($request->user()->onboarding_completed_at ? 'profile.edit' : 'onboarding')
                ->with('rouvy_status', ['type' => $ok ? 'success' : 'error', 'message' => $message]);
        }

        return Inertia::render('Garmin/Callback', ['ok' => $ok, 'message' => $message, 'service' => 'ROUVY'])->toResponse($request);
    }
}
