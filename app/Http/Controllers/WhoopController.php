<?php

namespace App\Http\Controllers;

use App\Jobs\ImportWhoopWorkoutJob;
use App\Jobs\SyncWhoopJob;
use App\Models\Activity;
use App\Models\User;
use App\Models\WhoopConnection;
use App\Models\WhoopWebhookEvent;
use App\Services\Whoop\WhoopClient;
use App\Services\Whoop\WhoopDisconnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Die WHOOP-Verbindung: verbinden, Rückweg, trennen, Webhook.
 *
 * Derselbe Ablauf wie bei Polar: der `state` liegt im Cache, der Rückweg
 * läuft ohne Anmeldung (iOS öffnet ihn aus der Homescreen-App in Safari).
 * **Ohne Einwilligung keine Verbindung** — die Werte gehen in die
 * KI-Auswertung (OpenAI).
 */
class WhoopController extends Controller
{
    private const CACHE_PREFIX = 'whoop-oauth:';
    private const PENDING_MINUTES = 10;

    /** So alt darf ein Webhook-Zeitstempel sein (gegen Wiederholung). */
    private const WEBHOOK_MAX_AGE_SECONDS = 600;

    public function __construct(
        private readonly WhoopClient $client,
        private readonly WhoopDisconnector $disconnector,
    ) {}

    public function connect(Request $request): Response
    {
        abort_unless($this->client->configured(), 404);
        $request->validate(['ai_consent' => 'accepted'], ['ai_consent.accepted' => 'Ohne Einwilligung kann Zone3 keine Daten von WHOOP übernehmen.']);

        OnboardingController::rememberConnectStep($request);

        $state = Str::random(40);
        Cache::put(self::CACHE_PREFIX . $state, ['user_id' => $request->user()->id], now()->addMinutes(self::PENDING_MINUTES));

        return Inertia::location($this->client->authorizationUrl($state));
    }

    public function callback(Request $request): Response
    {
        $state   = (string) $request->query('state');
        $pending = $state !== '' ? Cache::pull(self::CACHE_PREFIX . $state) : null;

        if ($request->filled('error')) {
            return $this->finish($request, false, 'WHOOP-Verbindung abgebrochen.');
        }
        if (! $pending) {
            return $this->finish($request, false, 'WHOOP-Verbindung abgelaufen. Bitte noch einmal verbinden.');
        }
        if ($request->user() && $request->user()->id !== $pending['user_id']) {
            return $this->finish($request, false, 'Diese WHOOP-Verbindung wurde von einem anderen Konto gestartet.');
        }

        $user = User::find($pending['user_id']);
        if (! $user || ! $request->filled('code')) {
            return $this->finish($request, false, 'WHOOP hat die Verbindung nicht bestätigt. Bitte noch einmal verbinden.');
        }

        try {
            $token   = $this->client->exchangeCode((string) $request->query('code'));
            $whoopId = (int) $this->client->profile($token['access_token'])['user_id'];
        } catch (Throwable $e) {
            Log::warning('WHOOP-Verbindung fehlgeschlagen', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return $this->finish($request, false, 'WHOOP hat die Verbindung nicht bestätigt. Bitte später noch einmal versuchen.');
        }

        // Ein WHOOP-Konto gehört genau einem Athleten.
        if (WhoopConnection::where('whoop_user_id', $whoopId)->where('user_id', '!=', $user->id)->exists()) {
            return $this->finish($request, false, 'Dieses WHOOP-Konto ist schon mit einem anderen Zone3-Konto verbunden.');
        }

        WhoopConnection::updateOrCreate(['user_id' => $user->id], WhoopClient::tokenFields($token) + [
            'whoop_user_id'        => $whoopId,
            'ai_consent_at'        => now(),
            'activities_synced_at' => null,
            'health_synced_at'     => null,
            'last_error'           => null,
            'last_error_at'        => null,
        ]);

        // WHOOP als einzige Quelle: dann gleich die gewählte.
        if ($user->fresh()->usableActivitySources() === [Activity::SOURCE_WHOOP]) {
            $user->forceFill(['activity_source' => Activity::SOURCE_WHOOP])->save();
        }

        Log::info('WHOOP verbunden', ['user_id' => $user->id]);
        SyncWhoopJob::dispatch($user->id);

        return $this->finish($request, true, 'WHOOP verbunden.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $this->disconnector->disconnect($request->user());

        return redirect()->route('profile.edit')
            ->with('whoop_status', ['type' => 'success', 'message' => 'WHOOP getrennt. Die übernommenen Daten sind gelöscht.']);
    }

    /**
     * WHOOP meldet eine Änderung. Signiert: Base64(HMAC-SHA256(Zeitstempel +
     * Body, Client-Secret)) in `X-WHOOP-Signature`. Der Body trägt nur
     * `user_id`, `id`, `type` und `trace_id` — die Daten holt der Job.
     * 2xx sofort, sonst stellt WHOOP bis zu fünfmal erneut zu.
     */
    public function webhook(Request $request): Response
    {
        $type    = (string) $request->input('type');
        $whoopId = $request->integer('user_id') ?: null;
        $id      = (string) $request->input('id');

        $record = WhoopWebhookEvent::create([
            'type'          => Str::limit($type ?: 'unknown', 40, ''),
            'whoop_user_id' => $whoopId,
            'entity_id'     => Str::limit($id, 64, ''),
            'outcome'       => 'received',
        ]);

        if (! $this->verifies($request)) {
            $record->update(['outcome' => 'bad_signature']);
            Log::warning('WHOOP-Webhook: Signatur passt nicht');

            return response()->noContent(401);
        }

        $trace = (string) $request->input('trace_id');
        if ($trace !== '' && ! Cache::add('whoop-webhook:' . $trace, true, now()->addDay())) {
            $record->update(['outcome' => 'duplicate']);

            return response()->noContent(200);
        }

        $connection = $whoopId ? WhoopConnection::where('whoop_user_id', $whoopId)->first() : null;
        if (! $connection) {
            $record->update(['outcome' => 'unknown_user']);

            return response()->noContent(200);
        }

        $outcome = match (true) {
            in_array($type, ['workout.updated', 'workout.deleted'], true) && Str::isUuid($id)
                => $this->queueWorkout($connection, $id, $type === 'workout.deleted', $record->id),
            in_array($type, ['sleep.updated', 'recovery.updated'], true)
                => $this->queueHealth($connection),
            default => 'ignored',
        };

        $record->update(['outcome' => $outcome]);

        return response()->noContent(200);
    }

    public function verifies(Request $request): bool
    {
        $secret    = (string) config('services.whoop.client_secret');
        $signature = (string) $request->header('X-WHOOP-Signature');
        $timestamp = (string) $request->header('X-WHOOP-Signature-Timestamp');

        if ($secret === '' || $signature === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        // WHOOP schickt Millisekunden.
        $seconds = strlen($timestamp) > 11 ? intdiv((int) $timestamp, 1000) : (int) $timestamp;
        if (abs(now()->timestamp - $seconds) > self::WEBHOOK_MAX_AGE_SECONDS) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $timestamp . $request->getContent(), $secret, true));

        return hash_equals($expected, $signature);
    }

    private function queueWorkout(WhoopConnection $connection, string $id, bool $deleted, int $eventId): string
    {
        ImportWhoopWorkoutJob::dispatch($connection->user_id, $id, $deleted, $eventId);

        return 'queued';
    }

    private function queueHealth(WhoopConnection $connection): string
    {
        // Ein paar Minuten warten: Schlaf und Recovery kommen kurz nacheinander.
        SyncWhoopJob::dispatch($connection->user_id, true)->delay(now()->addMinutes(2));

        return 'queued';
    }

    private function finish(Request $request, bool $ok, string $message): Response
    {
        if ($request->user()) {
            return redirect()->route($request->user()->onboarding_completed_at ? 'profile.edit' : 'onboarding')
                ->with('whoop_status', ['type' => $ok ? 'success' : 'error', 'message' => $message]);
        }

        return Inertia::render('Garmin/Callback', ['ok' => $ok, 'message' => $message, 'service' => 'WHOOP'])->toResponse($request);
    }
}
