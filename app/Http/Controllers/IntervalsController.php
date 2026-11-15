<?php

namespace App\Http\Controllers;

use App\Jobs\ImportIntervalsActivityJob;
use App\Jobs\SyncIntervalsWorkoutsJob;
use App\Models\IntervalsConnection;
use App\Models\IntervalsScheduledWorkout;
use App\Models\IntervalsWebhookEvent;
use App\Models\User;
use App\Services\Intervals\IntervalsClient;
use App\Services\Intervals\IntervalsDisconnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Die intervals.icu-Verbindung: verbinden, Rückweg, trennen, Webhook.
 *
 * intervals.icu ist bei Zone3 nur die Brücke zu Zwift (Jan, 13.11.2026):
 * Radeinheiten in den intervals-Kalender, Zwift-Fahrten zurück. Derselbe
 * Ablauf wie bei WHOOP: `state` und PKCE-Verifier im Cache, Rückweg ohne
 * Anmeldung (iOS öffnet ihn aus der Homescreen-App in Safari).
 * **Ohne Einwilligung keine Verbindung** — die Fahrten gehen in Review und
 * Plan (OpenAI).
 */
class IntervalsController extends Controller
{
    private const CACHE_PREFIX = 'intervals-oauth:';
    private const PENDING_MINUTES = 10;

    public function __construct(
        private readonly IntervalsClient $client,
        private readonly IntervalsDisconnector $disconnector,
    ) {}

    public function connect(Request $request): Response
    {
        abort_unless($this->client->configured(), 404);
        $request->validate(['ai_consent' => 'accepted'], ['ai_consent.accepted' => 'Ohne Einwilligung kann Zone3 keine Fahrten von intervals.icu übernehmen.']);

        OnboardingController::rememberConnectStep($request);

        $state    = Str::random(40);
        $verifier = Str::random(64);
        Cache::put(self::CACHE_PREFIX . $state, ['user_id' => $request->user()->id, 'verifier' => $verifier], now()->addMinutes(self::PENDING_MINUTES));

        return Inertia::location($this->client->authorizationUrl($state, IntervalsClient::codeChallenge($verifier)));
    }

    public function callback(Request $request): Response
    {
        $state   = (string) $request->query('state');
        $pending = $state !== '' ? Cache::pull(self::CACHE_PREFIX . $state) : null;

        if ($request->filled('error')) {
            return $this->finish($request, false, 'intervals.icu-Verbindung abgebrochen.');
        }
        if (! $pending) {
            return $this->finish($request, false, 'intervals.icu-Verbindung abgelaufen. Bitte noch einmal verbinden.');
        }
        if ($request->user() && $request->user()->id !== $pending['user_id']) {
            return $this->finish($request, false, 'Diese intervals.icu-Verbindung wurde von einem anderen Konto gestartet.');
        }

        $user = User::find($pending['user_id']);
        if (! $user || ! $request->filled('code')) {
            return $this->finish($request, false, 'intervals.icu hat die Verbindung nicht bestätigt. Bitte noch einmal verbinden.');
        }

        try {
            $token = $this->client->exchangeCode((string) $request->query('code'), $pending['verifier']);
        } catch (Throwable $e) {
            Log::warning('intervals.icu-Verbindung fehlgeschlagen', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return $this->finish($request, false, 'intervals.icu hat die Verbindung nicht bestätigt. Bitte später noch einmal versuchen.');
        }

        // Ein intervals-Konto gehört genau einem Athleten.
        if (IntervalsConnection::where('athlete_id', $token['athlete_id'])->where('user_id', '!=', $user->id)->exists()) {
            return $this->finish($request, false, 'Dieses intervals.icu-Konto ist schon mit einem anderen Zone3-Konto verbunden.');
        }

        IntervalsConnection::updateOrCreate(['user_id' => $user->id], [
            'athlete_id'    => $token['athlete_id'],
            'access_token'  => $token['access_token'],
            'scopes'        => $token['scope'],
            'ai_consent_at' => now(),
            'last_error'    => null,
            'last_error_at' => null,
        ]);
        // Ein neues Konto kennt unsere Events nicht: alles einmal neu schreiben.
        IntervalsScheduledWorkout::where('user_id', $user->id)->delete();

        Log::info('intervals.icu verbunden', ['user_id' => $user->id]);
        SyncIntervalsWorkoutsJob::dispatch($user->id);

        return $this->finish($request, true, 'intervals.icu verbunden.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $this->disconnector->disconnect($request->user());

        return redirect()->route('profile.edit')
            ->with('intervals_status', ['type' => 'success', 'message' => 'intervals.icu getrennt. Die Radeinheiten sind aus dem Kalender genommen.']);
    }

    /**
     * intervals.icu meldet Änderungen gebündelt:
     * `{"secret": "…", "events": [{"athlete_id": "…", "type": "ACTIVITY_UPLOADED", "activity": {…}}]}`.
     * Geprüft wird das Geheimnis aus der App (im Body und/oder im
     * Authorization-Header — die Doku nennt nur den Body). 2xx sofort, sonst
     * stellt intervals.icu mit wachsendem Abstand erneut zu.
     */
    public function webhook(Request $request): Response
    {
        $events = array_values(array_filter((array) $request->input('events', []), 'is_array'));

        if (! $this->verifies($request)) {
            IntervalsWebhookEvent::create(['type' => 'unknown', 'outcome' => 'bad_secret']);
            Log::warning('intervals.icu-Webhook: Geheimnis passt nicht');

            return response()->noContent(401);
        }

        foreach ($events as $event) {
            $this->handleEvent($event);
        }

        return response()->noContent(200);
    }

    public function verifies(Request $request): bool
    {
        $secret = (string) config('services.intervals.webhook_secret');
        if ($secret === '') {
            return false;
        }

        $header = trim((string) $request->header('Authorization'));
        $header = Str::startsWith($header, 'Bearer ') ? substr($header, 7) : $header;
        $body   = (string) $request->input('secret', '');

        return ($header !== '' && hash_equals($secret, $header))
            || ($body !== '' && hash_equals($secret, $body));
    }

    private function handleEvent(array $event): void
    {
        $type     = (string) ($event['type'] ?? 'unknown');
        $athlete  = (string) ($event['athlete_id'] ?? '');
        $activity = is_array($event['activity'] ?? null) ? $event['activity'] : null;
        $id       = (string) ($activity['id'] ?? $event['activity_id'] ?? '');

        $record = IntervalsWebhookEvent::create([
            'type'       => Str::limit($type, 40, ''),
            'athlete_id' => $athlete !== '' ? Str::limit($athlete, 32, '') : null,
            'entity_id'  => $id !== '' ? Str::limit($id, 64, '') : null,
            'outcome'    => 'received',
        ]);

        $connection = $athlete !== '' ? IntervalsConnection::where('athlete_id', $athlete)->first() : null;
        if (! $connection) {
            $record->update(['outcome' => 'unknown_user']);

            return;
        }

        $outcome = match (true) {
            in_array($type, ['ACTIVITY_UPLOADED', 'ACTIVITY_UPDATED'], true) && $id !== ''
                => $this->queueActivity($connection, $id, false, $record->id, $activity),
            $type === 'ACTIVITY_DELETED' && $id !== ''
                => $this->queueActivity($connection, $id, true, $record->id, null),
            $type === 'APP_SCOPE_CHANGED' => $this->scopeChanged($connection, $event),
            default => 'ignored',
        };

        $record->update(['outcome' => $outcome]);
    }

    private function queueActivity(IntervalsConnection $connection, string $id, bool $deleted, int $eventId, ?array $summary): string
    {
        ImportIntervalsActivityJob::dispatch($connection->user_id, $id, $deleted, $eventId, $summary);

        return 'queued';
    }

    /**
     * Der Athlet hat die Freigaben bei intervals.icu geändert. Fehlt der
     * Kalender, kann Zone3 nichts mehr nach Zwift schicken — das sagt die
     * Verbindung im Profil. Ohne Angabe wird nichts angenommen.
     */
    private function scopeChanged(IntervalsConnection $connection, array $event): string
    {
        $scope = $event['scope'] ?? $event['scopes'] ?? null;
        if (! is_string($scope)) {
            return 'noted';
        }

        $connection->update([
            'scopes'        => $scope,
            'last_error'    => str_contains($scope, 'CALENDAR') ? null : 'Kalender-Freigabe bei intervals.icu entzogen',
            'last_error_at' => str_contains($scope, 'CALENDAR') ? null : now(),
        ]);

        return 'scope_updated';
    }

    private function finish(Request $request, bool $ok, string $message): Response
    {
        if ($request->user()) {
            return redirect()->route($request->user()->onboarding_completed_at ? 'profile.edit' : 'onboarding')
                ->with('intervals_status', ['type' => $ok ? 'success' : 'error', 'message' => $message]);
        }

        return Inertia::render('Garmin/Callback', ['ok' => $ok, 'message' => $message, 'service' => 'intervals.icu'])->toResponse($request);
    }
}
