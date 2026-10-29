<?php

namespace App\Http\Controllers;

use App\Models\Coach;
use App\Models\Event;
use App\Models\RunnerProfile;
use App\Services\AI\AthleteProfileService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class OnboardingController extends Controller
{
    /**
     * Der letzte Schritt verbindet Strava und/oder Garmin. Beide schicken den
     * Athleten zu sich und wieder zurueck — das Onboarding muss danach beim
     * Verbinden weitermachen, nicht bei „Willkommen".
     */
    public const RESUME_KEY = 'onboarding.resume_step';

    /** Was ein fester Wochentermin sein kann. */
    private const FIXED_TYPES = ['interval', 'tempo_run', 'easy_run', 'long_run'];

    public function show()
    {
        $user = Auth::user();

        if ($user->onboarding_completed_at) {
            return redirect()->route('dashboard');
        }

        $coaches = Coach::all(['id', 'name', 'slug', 'specialty', 'tagline', 'description', 'avatar_color', 'avatar_initials']);

        return Inertia::render('Onboarding', [
            'coaches'     => $coaches,
            'resumeStep'  => session(self::RESUME_KEY),
            'connections' => fn () => $this->connections($user->fresh()),
        ]);
    }

    /**
     * Wer gerade aus dem Onboarding zu Strava oder Garmin geht, soll beim
     * Verbinden-Schritt wieder ankommen. In der Session der App, nicht im
     * Rueckweg: bei Garmin oeffnet iOS den Rueckweg in Safari, und dort gibt
     * es diese Session nicht — wohl aber, wenn der Athlet in die App
     * zurueckwechselt.
     */
    public static function rememberConnectStep(Request $request): void
    {
        if ($request->user() && ! $request->user()->onboarding_completed_at) {
            $request->session()->put(self::RESUME_KEY, 'connect');
        }
    }

    /** Stand der beiden Verbindungen fuer den letzten Schritt. */
    private function connections($user): array
    {
        return [
            'strava'          => $user->stravaAccount ? ['username' => $user->stravaAccount->username] : null,
            'garmin'          => $user->garminConnection?->summary(),
            'garminAvailable' => app(\App\Services\Garmin\GarminClient::class)->configured(),
            'garminStatus'    => session('garmin_status'),
            'source'          => [
                'current' => $user->activitySource(),
                'usable'  => $user->usableActivitySources(),
            ],
        ];
    }

    /**
     * Assign a coach to the user during onboarding.
     */
    public function saveCoach(Request $request)
    {
        $validated = $request->validate([
            'coach_id' => 'required|integer|exists:coaches,id',
        ]);

        $user = Auth::user();
        $user->coach_id = $validated['coach_id'];
        $user->save();

        return response()->json(['success' => true]);
    }

    /**
     * Estimate runner profile from simple race data via OpenAI.
     * Returns suggested values — user still confirms before saving.
     */
    public function estimateProfile(Request $request, AthleteProfileService $profiles)
    {
        $validated = $request->validate([
            'age'           => 'required|integer|min:14|max:90',
            'race_distance' => 'required|string|in:5km,10km,half_marathon,marathon',
            'race_time'     => ['required', 'string', 'regex:/^(\d+:)?\d{1,2}:\d{2}$/'],
            'weekly_runs'   => 'required|integer|min:1|max:14',
        ]);

        $estimate = $profiles->estimateProfileFromRaceData(
            $validated['age'],
            $validated['race_distance'],
            $validated['race_time'],
            $validated['weekly_runs'],
        );

        if (!$estimate) {
            return response()->json(['error' => 'Schätzung fehlgeschlagen. Bitte versuche es erneut.'], 500);
        }

        return response()->json($estimate);
    }

    /**
     * Save runner profile (manually entered or AI-estimated values).
     */
    public function saveProfile(Request $request)
    {
        $validated = $request->validate([
            'threshold_heart_rate' => 'required|integer|min:100|max:220',
            'max_heart_rate'       => 'required|integer|min:100|max:220',
            'threshold_speed'      => 'required|string|regex:/^[0-9]{1,2}:[0-9]{2}$/',
        ], [
            'threshold_speed.regex' => 'Bitte verwende das Format MM:SS (z.B. 5:30)',
        ]);

        $paceParts     = explode(':', $validated['threshold_speed']);
        $paceInMinutes = (float) ($paceParts[0] + ($paceParts[1] / 60));

        $user    = Auth::user();
        $profile = $user->runnerProfile ?? new RunnerProfile(['user_id' => $user->id]);

        $profile->user_id              = $user->id;
        $profile->threshold_heart_rate = (int) $validated['threshold_heart_rate'];
        $profile->max_heart_rate       = (int) $validated['max_heart_rate'];
        $profile->threshold_speed      = $paceInMinutes;
        $profile->heart_rate_zones     = $profile->calculateHeartRateZones();
        $profile->pace_zones           = $profile->calculatePaceZones();
        $profile->has_completed_setup  = true;
        $profile->save();

        return response()->json(['success' => true]);
    }

    /**
     * Save weekly availability.
     */
    public function saveAvailability(Request $request)
    {
        $validated = $request->validate([
            'availability'                => 'required|array',
            'availability.*.available'    => 'required|boolean',
            'availability.*.duration_min' => 'required|integer|min:0|max:300',

            // Zweites Zeitfenster am selben Tag (z. B. abends). Erst damit
            // kann das norwegische Modell zwei Einheiten an einen Tag legen.
            'availability.*.second_min'   => 'nullable|integer|min:0|max:180',

            // Fester woechentlicher Termin (Laufclub, Vereinstraining). Der
            // Inhalt wechselt und wird nicht geplant — der Tag ist trotzdem
            // belegt und die Einheit zaehlt fuer die Woche.
            'availability.*.fixed'        => 'nullable|array',
            'availability.*.fixed.type'   => ['nullable', Rule::in(self::FIXED_TYPES)],
            'availability.*.fixed.label'  => 'nullable|string|max:40',
        ]);

        foreach ($validated['availability'] as $day => $entry) {
            // Ein fester Termin an einem gesperrten Tag ergibt keinen Sinn,
            // ein zweites Zeitfenster auch nicht.
            if (empty($entry['available'])) {
                unset($validated['availability'][$day]['fixed'], $validated['availability'][$day]['second_min']);
                continue;
            }

            if (empty($entry['second_min'])) {
                unset($validated['availability'][$day]['second_min']);
            }

            if (! isset($entry['fixed'])) {
                continue;
            }

            // Bezeichnung und Typ sind Beiwerk, kein Grund zum Ablehnen: wer
            // den Termin gerade erst angelegt hat, hat noch nichts getippt.
            // Vorher scheiterte in dem Moment das Speichern der GESAMTEN
            // Verfuegbarkeit — und die Oberflaeche zeigte den Fehler nicht.
            $label = trim($entry['fixed']['label'] ?? '');

            $validated['availability'][$day]['fixed'] = [
                'type'  => $entry['fixed']['type'] ?? 'interval',
                'label' => $label !== '' ? $label : 'Fester Termin',
            ];
        }

        $user    = Auth::user();
        $profile = $user->runnerProfile ?? new RunnerProfile(['user_id' => $user->id]);
        $profile->user_id             = $user->id;
        $profile->weekly_availability = $validated['availability'];
        $profile->save();

        return response()->json(['success' => true]);
    }

    /**
     * Save strength & core training preferences.
     */
    public function saveStrength(Request $request)
    {
        $validated = $request->validate([
            'strength_enabled'       => 'required|boolean',
            'strength_days_per_week' => 'nullable|integer|min:1|max:4',
            'strength_equipment'     => 'nullable|array',
            'strength_equipment.*'   => 'string|in:kettlebell,dumbbells,gym,bodyweight,band',
            'strength_experience'    => 'nullable|in:beginner,intermediate,advanced',
        ]);

        $user    = Auth::user();
        $profile = $user->runnerProfile ?? new RunnerProfile(['user_id' => $user->id]);
        $profile->user_id                = $user->id;
        $profile->strength_enabled       = $validated['strength_enabled'];
        $profile->strength_days_per_week = $validated['strength_days_per_week'] ?? 2;
        $profile->strength_equipment     = $validated['strength_equipment'] ?? [];
        $profile->strength_experience    = $validated['strength_experience'] ?? null;
        $profile->save();

        return response()->json(['success' => true]);
    }

    /**
     * Trainingsmodell und was Zone3 dafür wissen muss. Wird auch aus dem
     * Profil aufgerufen, wie Kraft & Core.
     */
    public function saveTrainingModel(Request $request)
    {
        $validated = $request->validate([
            'training_model'           => ['required', Rule::in(RunnerProfile::TRAINING_MODELS)],
            'running_experience_years' => 'nullable|integer|min:0|max:60',
            'has_lactate_meter'        => 'required|boolean',
            'hills_available'          => 'sometimes|boolean',
        ]);

        $user    = Auth::user();
        $profile = $user->runnerProfile ?? new RunnerProfile(['user_id' => $user->id]);
        $profile->user_id = $user->id;
        $profile->fill(collect($validated)->except('training_model')->all());

        // Mit laufendem Plan wirkt der Wechsel ab dem nächsten Wochenplan
        // (Montag), sonst sofort — im Onboarding gibt es keine Woche, die
        // man umwerfen könnte.
        $profile->chooseTrainingModel(
            $validated['training_model'],
            immediately: ! $user->activeTrainingPlan()->exists(),
        );
        $profile->save();

        return response()->json([
            'success'   => true,
            'next'      => $profile->training_model_next,
            'next_from' => $profile->training_model_next_from?->toDateString(),
        ]);
    }

    /**
     * Rad / Zwift. Die FTP ist optional — ohne sie kann Zone3 keine
     * Radintervalle in % FTP schreiben, lockere Radeinheiten gehen trotzdem.
     */
    public function saveBike(Request $request)
    {
        $validated = $request->validate([
            'bike_enabled'          => 'required|boolean',
            'bike_season'           => ['required', Rule::in(RunnerProfile::BIKE_SEASONS)],
            'bike_purpose'          => ['required', Rule::in(RunnerProfile::BIKE_PURPOSES)],
            'bike_max_per_week'     => 'required|integer|min:1|max:4',
            'bike_preferred_days'   => 'nullable|array',
            'bike_preferred_days.*' => 'string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'ftp'                   => 'nullable|integer|min:50|max:600',
        ]);

        $user    = Auth::user();
        $profile = $user->runnerProfile ?? new RunnerProfile(['user_id' => $user->id]);
        $profile->user_id = $user->id;
        $profile->fill([
            ...$validated,
            'bike_preferred_days' => array_values(array_unique($validated['bike_preferred_days'] ?? [])),
        ])->save();

        return response()->json(['success' => true]);
    }

    /**
     * Save race goal as an Event (A-priority by default).
     */
    public function saveGoal(Request $request)
    {
        $validated = $request->validate([
            'name'                => 'required|string|max:150',
            'race_distance'       => 'required|string|in:5km,10km,half_marathon,marathon,custom,backyard_ultra',
            'distance_km'         => 'nullable|numeric|min:0.1',
            'target_time_hours'   => 'required_unless:race_distance,backyard_ultra|integer|min:0|max:23',
            'target_time_minutes' => 'required_unless:race_distance,backyard_ultra|integer|min:0|max:59',
            'target_yards'        => 'required_if:race_distance,backyard_ultra|nullable|integer|min:1|max:100',
            'race_date'           => 'required|date|after:today',
        ]);

        Event::create([
            'user_id'             => Auth::id(),
            'name'                => $validated['name'],
            'event_date'          => $validated['race_date'],
            'race_distance'       => $validated['race_distance'],
            'distance_km'         => $validated['distance_km'] ?? null,
            'priority'            => 'A',
            'target_time_hours'   => $validated['target_time_hours'] ?? 0,
            'target_time_minutes' => $validated['target_time_minutes'] ?? 0,
            'target_yards'        => $validated['target_yards'] ?? null,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Reset onboarding so the user can go through it again.
     */
    public function reset(Request $request)
    {
        $user = Auth::user();
        $user->onboarding_completed_at = null;
        $user->save();

        return redirect()->route('onboarding');
    }

    public function complete(Request $request)
    {
        $user = Auth::user();
        $user->onboarding_completed_at = now();

        // Assign Max (companion) as default if user skipped coach selection
        if (! $user->coach_id) {
            $default = Coach::where('slug', 'max')->first();
            if ($default) {
                $user->coach_id = $default->id;
            }
        }

        $user->save();
        $request->session()->forget(self::RESUME_KEY);

        return redirect()->route('dashboard');
    }
}
