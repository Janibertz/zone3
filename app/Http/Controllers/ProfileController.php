<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Activity;
use App\Models\Coach;
use App\Services\BestEffortService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use App\Services\PaceFormat;

class ProfileController extends Controller
{
    public function edit(Request $request, BestEffortService $bestEfforts): Response
    {
        $user = $request->user();

        // Athletenzahlen — ausschliesslich Laeufe.
        //
        // Die Abfrage lief vorher ueber alle Aktivitaeten. Damit war der
        // laengste "Lauf" eine Radtour, und die "Ø Pace" ein Mittelwert aus
        // Laufen, Radfahren und Schwimmen — eine Zahl, die es so nicht gibt.
        //
        // Auch der Mittelwert selbst war schief: der Durchschnitt der
        // Einzelgeschwindigkeiten gewichtet einen Dreikilometerlauf genauso
        // stark wie einen Marathon. Richtig ist Gesamtstrecke durch
        // Gesamtzeit.
        $stats = Activity::where('user_id', $user->id)
            ->where('type', 'Run')
            ->selectRaw('
                COUNT(*) as total_runs,
                COALESCE(SUM(distance), 0) as total_distance,
                COALESCE(SUM(moving_time), 0) as total_time,
                COALESCE(MAX(distance), 0) as longest_run
            ')->first();

        $avgSpeed = $stats->total_time > 0
            ? $stats->total_distance / $stats->total_time
            : 0;

        // Ein Modellwechsel, dessen Montag vorbei ist, gilt — sonst stünde
        // im Profil „gilt ab" mit einem Datum in der Vergangenheit.
        $user->runnerProfile?->settlePendingTrainingModel();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail'  => $user instanceof MustVerifyEmail,
            'status'           => session('status'),
            'runnerProfile'    => $user->runnerProfile,
            // Woher Zonen und Prognosen kommen — siehe VdotService.
            'vdot'             => $user->runnerProfile?->vdotInfo(),
            'ftpSuggestion'    => app(\App\Services\Bike\FtpCheck::class)->suggestionFor($user),
            'norwegianReadiness' => app(\App\Services\TrainingModelService::class)->norwegianReadiness($user->runnerProfile, $user->id),
            'stravaConnected'  => (bool) $user->stravaAccount,
            // Offizielle Garmin-Verbindung. `available` ist false, solange
            // GARMIN_CLIENT_ID/SECRET fehlen — dann bleibt nur der alte Login.
            'garminOfficial'   => [
                'available'  => app(\App\Services\Garmin\GarminClient::class)->configured(),
                'connection' => $user->garminConnection?->summary(),
                'status'     => session('garmin_status'),
                'workoutSync'=> (bool) ($user->garmin_workout_sync ?? true),
            ],
            // Woher Aktivitäten kommen. Wählbar nur, wenn beide liefern können.
            'activitySource'   => [
                'current' => $user->activitySource(),
                'usable'  => $user->usableActivitySources(),
            ],
            'stravaAccount'    => $user->stravaAccount ? [
                'username'       => $user->stravaAccount->username,
                'last_synced_at' => $user->stravaAccount->last_synced_at,
            ] : null,
            'notificationSettings' => [
                'push_enabled'            => (bool) $user->push_notifications_enabled,
                'wellbeing_reminder_time' => $user->wellbeing_reminder_time ?? '08:00',
                'notify_threshold_pace'   => (bool) ($user->notify_threshold_pace ?? true),
                'notify_plan_updated'     => (bool) ($user->notify_plan_updated ?? true),
                'notify_monthly_review'   => (bool) ($user->notify_monthly_review ?? true),
            ],
            'vapidPublicKey' => config('services.webpush.public_key'),
            'telegram' => [
                'available' => app(\App\Services\TelegramClient::class)->configured(),
                'linked'    => $user->hasTelegram(),
                'linked_at' => $user->telegram_linked_at?->toIso8601String(),
            ],
            // Kalender-Abo: webcal:// öffnet auf dem iPhone direkt den Abo-Dialog.
            'calendarFeed' => $user->calendar_token ? [
                'url'    => $url = route('calendar-feed.show', $user->calendar_token),
                'webcal' => preg_replace('#^https?://#', 'webcal://', $url),
            ] : null,
            'coaches'   => Coach::all(['id', 'name', 'slug', 'specialty', 'tagline', 'description', 'avatar_color', 'avatar_initials']),
            'activeCoach' => $user->coach ? [
                'id'              => $user->coach->id,
                'name'            => $user->coach->name,
                'specialty'       => $user->coach->specialty,
                'tagline'         => $user->coach->tagline,
                'avatar_color'    => $user->coach->avatar_color,
                'avatar_initials' => $user->coach->avatar_initials,
            ] : null,
            'athleteStats' => [
                'total_runs'      => (int) $stats->total_runs,
                'total_km'        => round($stats->total_distance / 1000, 1),
                'longest_km'      => round($stats->longest_run / 1000, 2),
                'avg_pace'        => $this->speedToPace($avgSpeed),
            ],
            // Personal records (top 3 per distance) + per-distance history for the chart
            'personalRecords' => $bestEfforts->topThree($user->id),
            'prHistory'       => $bestEfforts->history($user->id),
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-information-updated');
    }

    public function updateAvatar(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $user = $request->user();

        // Delete old avatar
        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar' => $path]);

        return Redirect::route('profile.edit')->with('status', 'avatar-updated');
    }

    public function updateCoach(Request $request): RedirectResponse
    {
        $request->validate([
            'coach_id' => 'required|integer|exists:coaches,id',
        ]);

        $request->user()->update(['coach_id' => $request->coach_id]);

        return Redirect::route('profile.edit')->with('status', 'coach-updated');
    }

    /**
     * „Aktivitäten kommen von" — eine Quelle pro Athlet. Gewählt werden kann
     * nur, was gerade liefern kann; sonst stünde eine Wahl da, die
     * `User::activitySource()` stillschweigend übergeht.
     */
    public function updateActivitySource(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'source' => ['required', 'string', \Illuminate\Validation\Rule::in($user->usableActivitySources())],
        ]);

        $user->update(['activity_source' => $request->source]);

        // Zurueck, woher die Wahl kam: Profil oder der Verbinden-Schritt im Onboarding.
        return Redirect::back()->with('status', 'activity-source-updated');
    }

    /**
     * Plan automatisch auf die Uhr — an oder aus. Aus raeumt weg, was Zone3
     * fuer die kommenden Tage in den Garmin-Kalender gelegt hat.
     */
    public function updateGarminWorkoutSync(Request $request): RedirectResponse
    {
        $request->validate(['enabled' => 'required|boolean']);

        $user = $request->user();
        $user->update(['garmin_workout_sync' => $request->boolean('enabled')]);

        \App\Jobs\SyncGarminWorkoutsJob::dispatch($user->id);

        return Redirect::back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // Bei Garmin abmelden, solange der Token noch da ist — sonst bliebe
        // Zone3 dort als verbundene App stehen.
        if ($user->garminConnection) {
            app(\App\Services\Garmin\GarminDisconnector::class)->disconnect($user);
        }

        Auth::logout();

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    private function speedToPace(float $speed): string
    {
        return $speed > 0 ? PaceFormat::fromSpeed($speed) : '–';
    }
}
