<?php

namespace App\Http\Controllers;

use App\Jobs\SyncCorosHealthJob;
use App\Jobs\SyncCorosWorkoutsJob;
use App\Models\CorosConnection;
use App\Models\User;
use App\Services\Coros\CorosClient;
use App\Services\Coros\CorosDisconnector;
use App\Services\Coros\CorosHealthImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Die COROS-Verbindung: verbinden, Rueckweg, trennen, Plan auf die Uhr.
 *
 * Derselbe Ablauf wie bei Garmin (`GarminController`): PKCE, das Geheimnis
 * im Cache unter dem `state`, der Rueckweg ohne Anmeldung, weil iOS ihn aus
 * der Homescreen-App in Safari oeffnet.
 *
 * Gelesen wird von COROS nur mit Einwilligung (Bedingung von COROS, Mail
 * vom 05.11.2026): `ai_consent` beim Verbinden oder spaeter der Schalter
 * „Daten übernehmen“ im Profil. Ohne sie schreibt Zone3 nur Workouts.
 */
class CorosController extends Controller
{
    private const CACHE_PREFIX = 'coros-oauth:';
    private const PENDING_MINUTES = 10;

    public function __construct(
        private readonly CorosClient $client,
        private readonly CorosDisconnector $disconnector,
    ) {}

    public function connect(Request $request): Response
    {
        abort_unless($this->client->enabled(), 404);

        try {
            $clientId = $this->client->clientId();
        } catch (Throwable $e) {
            Log::warning('COROS-Registrierung fehlgeschlagen', ['error' => $e->getMessage()]);

            return $this->finish($request, false, 'COROS ist gerade nicht erreichbar. Bitte später noch einmal versuchen.');
        }

        OnboardingController::rememberConnectStep($request);

        $state    = Str::random(40);
        $verifier = $this->client->makeVerifier();

        Cache::put(self::CACHE_PREFIX . $state, [
            'user_id'   => $request->user()->id,
            'verifier'  => $verifier,
            'client_id' => $clientId,
            'consent'   => $request->boolean('ai_consent'),
        ], now()->addMinutes(self::PENDING_MINUTES));

        return Inertia::location($this->client->authorizationUrl($clientId, $state, $verifier));
    }

    public function callback(Request $request): Response
    {
        $state   = (string) $request->query('state');
        $pending = $state !== '' ? Cache::pull(self::CACHE_PREFIX . $state) : null;

        if ($request->filled('error')) {
            return $this->finish($request, false, 'COROS-Verbindung abgebrochen.');
        }

        if (! $pending) {
            return $this->finish($request, false, 'COROS-Verbindung abgelaufen. Bitte noch einmal verbinden.');
        }

        if ($request->user() && $request->user()->id !== $pending['user_id']) {
            return $this->finish($request, false, 'Diese COROS-Verbindung wurde von einem anderen Konto gestartet.');
        }

        $user = User::find($pending['user_id']);
        if (! $user || ! $request->filled('code')) {
            return $this->finish($request, false, 'COROS hat die Verbindung nicht bestätigt. Bitte noch einmal verbinden.');
        }

        try {
            $token = $this->client->exchangeCode($pending['client_id'], (string) $request->query('code'), $pending['verifier']);
        } catch (Throwable $e) {
            Log::warning('COROS-Verbindung fehlgeschlagen', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return $this->finish($request, false, 'COROS hat die Verbindung nicht bestätigt. Bitte später noch einmal versuchen.');
        }

        // Ein COROS-Konto gehoert genau einem Athleten.
        $corosId = $this->client->subject($token);
        if ($corosId && CorosConnection::where('coros_user_id', $corosId)->where('user_id', '!=', $user->id)->exists()) {
            return $this->finish($request, false, 'Dieses COROS-Konto ist schon mit einem anderen Zone3-Konto verbunden.');
        }

        CorosConnection::updateOrCreate(
            ['user_id' => $user->id],
            $this->client->tokenFields($token) + [
                'coros_user_id'     => $corosId,
                'client_id'         => $pending['client_id'],
                'last_refreshed_at' => now(),
                'refresh_error'     => null,
                'refresh_error_at'  => null,
            ] + (! empty($pending['consent']) ? ['ai_consent_at' => now()] : []),
        );

        Log::info('COROS verbunden', ['user_id' => $user->id]);

        SyncCorosWorkoutsJob::dispatch($user->id);
        if (! empty($pending['consent'])) {
            SyncCorosHealthJob::dispatch($user->id);
        }

        return $this->finish($request, true, 'COROS verbunden.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $this->disconnector->disconnect($request->user());

        return redirect()->route('profile.edit')
            ->with('coros_status', ['type' => 'success', 'message' => 'COROS getrennt.']);
    }

    /** Plan auf die Uhr an oder aus. Aus sagt ab, was schon dort steht. */
    public function updateWorkoutSync(Request $request): RedirectResponse
    {
        $request->validate(['enabled' => 'required|boolean']);

        $connection = $request->user()->corosConnection;
        abort_unless($connection, 404);

        $connection->update(['workout_sync' => $request->boolean('enabled')]);
        SyncCorosWorkoutsJob::dispatch($request->user()->id);

        return back();
    }

    /**
     * Daten von COROS uebernehmen (Einwilligung) an oder aus. Aus widerruft:
     * die Erholungswerte von COROS werden geloescht.
     */
    public function updateDataImport(Request $request, CorosHealthImporter $health): RedirectResponse
    {
        $request->validate(['enabled' => 'required|boolean']);

        $connection = $request->user()->corosConnection;
        abort_unless($connection, 404);

        if ($request->boolean('enabled')) {
            $request->validate(['ai_consent' => 'accepted']);
            $connection->update(['ai_consent_at' => $connection->ai_consent_at ?? now()]);
            SyncCorosHealthJob::dispatch($request->user()->id);
        } else {
            $connection->update(['ai_consent_at' => null, 'health_synced_at' => null]);
            $health->forget($request->user());
        }

        return back();
    }

    private function finish(Request $request, bool $ok, string $message): Response
    {
        if ($request->user()) {
            // Aus dem Onboarding gestartet: dorthin zurueck, wie bei Garmin.
            return redirect()->route($request->user()->onboarding_completed_at ? 'profile.edit' : 'onboarding')
                ->with('coros_status', ['type' => $ok ? 'success' : 'error', 'message' => $message]);
        }

        return Inertia::render('Garmin/Callback', ['ok' => $ok, 'message' => $message, 'service' => 'COROS'])->toResponse($request);
    }
}
