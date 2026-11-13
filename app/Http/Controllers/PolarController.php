<?php

namespace App\Http\Controllers;

use App\Jobs\ImportPolarExerciseJob;
use App\Jobs\SyncPolarJob;
use App\Models\PolarConnection;
use App\Models\PolarWebhookEvent;
use App\Models\User;
use App\Services\Polar\PolarClient;
use App\Services\Polar\PolarDisconnector;
use App\Services\Polar\PolarWebhook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Die Polar-Verbindung: verbinden, Rückweg, trennen, Webhook.
 *
 * Derselbe Ablauf wie bei Garmin und COROS: der `state` liegt im Cache, der
 * Rückweg läuft ohne Anmeldung, weil iOS ihn aus der Homescreen-App in
 * Safari öffnet. Polar ist reines Lesen — **ohne Einwilligung keine
 * Verbindung** (Polar-Vertrag §3.1.1: Weitergabe nur mit ausdrücklicher
 * Erlaubnis; hier an OpenAI).
 */
class PolarController extends Controller
{
    private const CACHE_PREFIX = 'polar-oauth:';
    private const PENDING_MINUTES = 10;

    public function __construct(
        private readonly PolarClient $client,
        private readonly PolarDisconnector $disconnector,
    ) {}

    public function connect(Request $request): Response
    {
        abort_unless($this->client->configured(), 404);
        $request->validate(['ai_consent' => 'accepted'], ['ai_consent.accepted' => 'Ohne Einwilligung kann Zone3 keine Daten von Polar übernehmen.']);

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
            return $this->finish($request, false, 'Polar-Verbindung abgebrochen.');
        }
        if (! $pending) {
            return $this->finish($request, false, 'Polar-Verbindung abgelaufen. Bitte noch einmal verbinden.');
        }
        if ($request->user() && $request->user()->id !== $pending['user_id']) {
            return $this->finish($request, false, 'Diese Polar-Verbindung wurde von einem anderen Konto gestartet.');
        }

        $user = User::find($pending['user_id']);
        if (! $user || ! $request->filled('code')) {
            return $this->finish($request, false, 'Polar hat die Verbindung nicht bestätigt. Bitte noch einmal verbinden.');
        }

        try {
            $token  = $this->client->exchangeCode((string) $request->query('code'));
            $polarId = (int) $token['x_user_id'];

            // Ein Polar-Konto gehört genau einem Athleten.
            if (PolarConnection::where('polar_user_id', $polarId)->where('user_id', '!=', $user->id)->exists()) {
                return $this->finish($request, false, 'Dieses Polar-Konto ist schon mit einem anderen Zone3-Konto verbunden.');
            }

            $this->client->register($token['access_token'], 'zone3-' . $user->id);
        } catch (Throwable $e) {
            Log::warning('Polar-Verbindung fehlgeschlagen', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return $this->finish($request, false, str_contains($e->getMessage(), 'Pflicht-Einwilligungen')
                ? 'Polar verlangt, dass du in Polar Flow zuerst alle Einwilligungen bestätigst. Danach noch einmal verbinden.'
                : 'Polar hat die Verbindung nicht bestätigt. Bitte später noch einmal versuchen.');
        }

        PolarConnection::updateOrCreate(['user_id' => $user->id], [
            'polar_user_id' => $polarId,
            'access_token'  => $token['access_token'],
            'ai_consent_at' => now(),
            'last_error'    => null,
            'last_error_at' => null,
        ]);

        // Polar als einzige Quelle: dann gleich die gewählte.
        if ($user->fresh()->usableActivitySources() === [\App\Models\Activity::SOURCE_POLAR]) {
            $user->forceFill(['activity_source' => \App\Models\Activity::SOURCE_POLAR])->save();
        }

        Log::info('Polar verbunden', ['user_id' => $user->id]);
        SyncPolarJob::dispatch($user->id);

        return $this->finish($request, true, 'Polar verbunden.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $this->disconnector->disconnect($request->user());

        return redirect()->route('profile.edit')
            ->with('polar_status', ['type' => 'success', 'message' => 'Polar getrennt. Die übernommenen Daten sind gelöscht.']);
    }

    /**
     * Polar meldet ein neues Training. Signiert (HMAC-SHA256 über den Body),
     * anders als Garmin und Strava. Nur der PING beim Anlegen kommt vor dem
     * Schlüssel an — er tut nichts und bekommt 200, sonst legt Polar den
     * Webhook nicht an.
     */
    public function webhook(Request $request, PolarWebhook $webhook): Response
    {
        $event = strtoupper((string) ($request->header('Polar-Webhook-Event') ?: $request->input('event')));

        if ($event === 'PING') {
            return response()->noContent(200);
        }

        $polarId  = $request->integer('user_id') ?: null;
        $entityId = (string) $request->input('entity_id');
        $record   = PolarWebhookEvent::create([
            'event'         => Str::limit($event ?: 'unknown', 40, ''),
            'polar_user_id' => $polarId,
            'entity_id'     => Str::limit($entityId, 64, ''),
            'outcome'       => 'received',
        ]);

        if (! $webhook->verifies($request->getContent(), $request->header('Polar-Webhook-Signature'))) {
            $record->update(['outcome' => 'bad_signature']);
            Log::warning('Polar-Webhook: Signatur passt nicht');

            return response()->noContent(401);
        }

        if ($event !== 'EXERCISE' || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $entityId)) {
            $record->update(['outcome' => 'ignored']);

            return response()->noContent(200);
        }

        $connection = $polarId ? PolarConnection::where('polar_user_id', $polarId)->first() : null;
        if (! $connection) {
            $record->update(['outcome' => 'unknown_user']);

            return response()->noContent(200);
        }

        // 200 sofort, der Import (zwei Abrufe bei Polar) läuft in der Queue.
        ImportPolarExerciseJob::dispatch($connection->user_id, $entityId, $record->id);
        $record->update(['outcome' => 'queued']);

        return response()->noContent(200);
    }

    private function finish(Request $request, bool $ok, string $message): Response
    {
        if ($request->user()) {
            return redirect()->route($request->user()->onboarding_completed_at ? 'profile.edit' : 'onboarding')
                ->with('polar_status', ['type' => $ok ? 'success' : 'error', 'message' => $message]);
        }

        return Inertia::render('Garmin/Callback', ['ok' => $ok, 'message' => $message, 'service' => 'Polar'])->toResponse($request);
    }
}
