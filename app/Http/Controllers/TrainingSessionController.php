<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateRacePredictionJob;
use App\Jobs\GenerateSessionReviewJob;
use App\Jobs\RegeneratePlanJob;
use App\Models\TrainingSession;
use App\Services\AI\CoachingTextService;
use App\Services\AI\SessionContentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Services\PaceFormat;
use App\Services\WorkoutPaceResolver;

class TrainingSessionController extends Controller
{
    /**
     * Mark a session as completed and let the coach adapt the remaining plan.
     */
    public function complete(TrainingSession $session)
    {
        abort_if($session->user_id !== Auth::id(), 403);
        abort_if($session->status !== 'planned', 422);

        $session->update(['status' => 'completed']);

        // BEWUSST KEINE Neuberechnung des Plans.
        //
        // Eine abgehakte Einheit ist kein Anlass: der Athlet hat getan, was
        // im Plan stand, und damit ist nichts eingetreten, worauf der Plan
        // reagieren muesste. Vorher warf genau das den gesamten Restplan neu
        // — und weil ein Sprachmodell nicht deterministisch ist, kam jedes
        // Mal etwas anderes heraus. Ruhetage verschwanden, ein Schwellenlauf
        // wurde ueber Nacht zu zwanzig lockeren Minuten.
        //
        // Was die Einheit an neuer Erkenntnis bringt, fliesst ueber den
        // Kontext in die naechste planmaessige Neuberechnung ein.
        $this->refreshCoachMessages();

        if ($session->trainingPlan) {
            GenerateRacePredictionJob::dispatch($session->trainingPlan->id)->delay(now()->addSeconds(15));
        }

        // Coach review of the completed session. Delayed so a matching Strava import
        // (webhook) can attach the activity first and enrich the review with real
        // metrics; the job is idempotent (reviewed_at guard) if the webhook wins.
        GenerateSessionReviewJob::dispatch($session->id)->delay(now()->addSeconds(120));

        return response()->json(['session' => $this->formatSession($session)]);
    }

    /**
     * Mark a session as skipped and let the coach adapt the remaining plan.
     */
    /**
     * Per Geste verschieben: lange druecken, auf einen anderen Tag ziehen.
     * Die Regeln stehen in {@see \App\Services\SessionScheduleService}.
     */
    public function move(Request $request, TrainingSession $session, \App\Services\SessionScheduleService $schedule)
    {
        abort_if($session->user_id !== Auth::id(), 403);

        $data = $request->validate(['date' => 'required|date_format:Y-m-d']);

        try {
            $schedule->move($session, $data['date']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    /** Per Wischen loeschen. Ein geleerter Plantag bekommt einen Ruhetag. */
    public function destroy(TrainingSession $session, \App\Services\SessionScheduleService $schedule)
    {
        abort_if($session->user_id !== Auth::id(), 403);

        try {
            $schedule->delete($session);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    public function skip(Request $request, TrainingSession $session)
    {
        abort_if($session->user_id !== Auth::id(), 403);
        abort_if($session->status !== 'planned', 422);

        $request->validate([
            'reason' => 'nullable|string|max:200',
        ]);

        $session->update([
            'status'      => 'skipped',
            'skip_reason' => $request->reason,
        ]);

        // Eine ausgelassene Einheit IST ein Anlass — hier fehlt dem Plan
        // etwas, und der Athlet erwartet, dass der Coach darauf eingeht.
        $this->triggerCoachReaction($session, RegeneratePlanJob::REASON_SKIP);

        return response()->json(['session' => $this->formatSession($session)]);
    }

    /**
     * Den Plan zur Neuberechnung vormerken — nur mit ausdruecklichem Anlass.
     *
     * Der Anlass wandert bis in den Job durch und entscheidet dort, wie weit
     * die Neuberechnung in die nahe Zukunft hineinreichen darf.
     */
    private function triggerCoachReaction(TrainingSession $session, string $reason): void
    {
        $plan = $session->trainingPlan;

        if ($plan) {
            $plan->update(['needs_plan_update' => true]);
            RegeneratePlanJob::dispatch($session->user_id, $reason)->delay(now()->addSeconds(10));

            if ($session->status === 'completed') {
                GenerateRacePredictionJob::dispatch($plan->id)->delay(now()->addSeconds(15));
            }
        }

        $this->refreshCoachMessages();
    }

    /**
     * Die zwischengespeicherten Coach-Texte verwerfen, damit das Dashboard
     * sie mit dem neuen Stand neu schreibt. Das hat mit der Planung nichts
     * zu tun und kostet keine Planberechnung.
     */
    private function refreshCoachMessages(): void
    {
        Auth::user()->runnerProfile?->update([
            'today_recommendation' => null,
            'recommendation_date'  => null,
            'daily_message'        => null,
            'daily_message_date'   => null,
        ]);
    }

    /**
     * Store the athlete's answer to the coach's review follow-up question and
     * remember it on the runner profile so future plan generation picks it up.
     */
    public function reviewFeedback(Request $request, TrainingSession $session)
    {
        abort_if($session->user_id !== Auth::id(), 403);
        abort_if(empty($session->review_question), 422);

        $request->validate([
            'feedback' => 'required|string|max:300',
        ]);

        $answer = trim($request->input('feedback'));
        $session->update(['review_feedback' => $answer]);

        // Die Notiz landet im Gedächtnis des Coaches — und seit dem
        // PlanContextBuilder auch im Prompt der Planerstellung.
        $date = $session->planned_date->format('d.m.Y');
        \App\Models\RunnerProfile::firstOrCreate(['user_id' => Auth::id()])->rememberNote(
            "Rückmeldung zu \"{$session->review_question}\" ({$session->title}, {$date}): {$answer}"
        );

        // Die Antwort fliesst ueber coach_notes in die naechste planmaessige
        // Neuberechnung ein — sie loest keine eigene aus. Wer schreibt, dass
        // die Einheit zu hart war, meint damit die kommenden Wochen und
        // nicht den Plan fuer morgen frueh.
        $this->refreshCoachMessages();

        return response()->json(['session' => $this->formatSession($session->fresh())]);
    }

    /**
     * Save user rating + perceived effort for a completed session.
     */
    public function rate(Request $request, TrainingSession $session)
    {
        abort_if($session->user_id !== Auth::id(), 403);

        $request->validate([
            'rating'           => 'nullable|integer|min:1|max:5',
            'effort_perceived' => 'nullable|integer|min:1|max:10',
            'feeling_notes'    => 'nullable|string|max:300',
        ]);

        $session->update([
            'rating'           => $request->input('rating')           ?: null,
            'effort_perceived' => $request->input('effort_perceived') ?: null,
            'feeling_notes'    => $request->filled('feeling_notes') ? trim($request->input('feeling_notes')) : null,
        ]);

        return response()->json(['session' => $this->formatSession($session->fresh())]);
    }

    /**
     * Admin: clear cached steps and nutrition tips so they get regenerated on next request.
     */
    public function resetCache(TrainingSession $session)
    {
        abort_if(! Auth::user()->is_admin, 403); // admin-only; ownership intentionally not required

        $update = ['steps' => null, 'nutrition_tips' => null];

        // Strength/core/mobility sessions may carry a run description that was wrongly
        // overwritten by an earlier steps generation — rebuild it from the exercises.
        if (in_array($session->type, ['strength', 'core', 'mobility']) && ! empty($session->exercises)) {
            $update['description'] = $this->buildDescriptionFromExercises($session);
        }

        $session->update($update);

        return response()->json(['session' => $this->formatSession($session->fresh())]);
    }

    /** Build a German strength-session description from the structured exercise list. */
    private function buildDescriptionFromExercises(TrainingSession $session): string
    {
        $label = [
            'strength' => 'Krafttraining',
            'core'     => 'Core-Training',
            'mobility' => 'Mobility-Einheit',
        ][$session->type] ?? 'Krafttraining';

        $parts = [];
        foreach ((array) $session->exercises as $ex) {
            $name = $ex['name'] ?? null;
            if (! $name) continue;
            $setsReps = trim(implode('×', array_filter([$ex['sets'] ?? null, $ex['reps'] ?? null])));
            $parts[]  = $setsReps ? "{$name} ({$setsReps})" : $name;
        }

        return $parts
            ? "{$label}: " . implode(', ', $parts) . '.'
            : "{$label}.";
    }

    /**
     * Return AI nutrition tips for a session — served from DB cache, generated on first request.
     */
    public function nutritionTips(TrainingSession $session, SessionContentService $sessions)
    {
        abort_if($session->user_id !== Auth::id(), 403);
        // Completed sessions show real Strava splits — no nutrition tips generated.
        abort_if($session->status === 'completed', 422);

        // Return cached tips if available
        if ($session->nutrition_tips) {
            return response()->json($session->nutrition_tips);
        }

        $sessions->withCoach(Auth::user()->coach?->personality_prompt)->forUser(Auth::id());
        $tips = $sessions->generateNutritionTips([
            'type'         => $session->type,
            'title'        => $session->title,
            'distance_km'  => $session->distance_km,
            'duration_min' => $session->duration_min,
            'pace_target'  => $session->pace_target,
            'intensity'    => $session->intensity,
            'is_race'      => $session->type === 'race_prep' ? 'Ja' : 'Nein',
            'is_strength'  => in_array($session->type, ['strength', 'core', 'mobility']),
            'exercises'    => $session->exercises,
        ]);

        if (! $tips) {
            return response()->json(['error' => 'Tipps konnten nicht geladen werden.'], 500);
        }

        // Cache to DB for future requests
        $session->update(['nutrition_tips' => $tips]);

        return response()->json($tips);
    }

    /**
     * Return structured workout steps for a session — served from DB cache, generated on first request.
     * Only for non-rest, non-race sessions.
     */
    /**
     * Die Radeinheit als .zwo-Datei für Zwift.
     *
     * Nur für Einheiten, deren Schritte in % FTP stehen — eine Laufstruktur
     * in eine Zwift-Datei zu schreiben, ergäbe eine Fahrt ohne Leistungsziel.
     */
    public function zwo(TrainingSession $session, \App\Services\Bike\ZwoWriter $writer)
    {
        abort_if($session->user_id !== Auth::id(), 403);

        abort_unless($session->hasBikeSteps(), 404);

        $xml  = $writer->write($session->title, (string) $session->description, $session->steps);
        $name = \Illuminate\Support\Str::slug('zone3-' . $session->planned_date->format('Y-m-d') . '-' . str_replace('×', 'x', $session->title)) . '.zwo';

        return response($xml, 200, [
            'Content-Type'        => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }

    /**
     * Die Laufeinheit als .workout-Datei für die Apple Watch.
     *
     * Antippen auf dem iPhone öffnet die Fitness-App mit „Hinzufügen" — danach
     * steht das Training mit Abschnitten und Pace-Alarm in der Training-App.
     */
    public function appleWorkout(TrainingSession $session, \App\Services\Watch\AppleWorkoutWriter $writer)
    {
        abort_if($session->user_id !== Auth::id(), 403);

        abort_unless($writer::supports($session), 404);

        $bytes = $writer->write((string) $session->title, $writer->stepsFor($session));
        $name  = \Illuminate\Support\Str::slug('zone3-' . $session->planned_date->format('Y-m-d') . '-' . str_replace('×', 'x', $session->title)) . '.workout';

        return response($bytes, 200, [
            'Content-Type'        => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }

    public function sessionSteps(TrainingSession $session, SessionContentService $sessions)
    {
        abort_if($session->user_id !== Auth::id(), 403);
        // Strength/core/mobility sessions carry their own "exercises" list — no run
        // structure is generated for them (it would also overwrite the description).
        abort_if(in_array($session->type, ['rest', 'race_prep', 'strength', 'core', 'mobility']), 422);
        // Completed sessions show real Strava splits — no planned structure generated.
        abort_if($session->status === 'completed', 422);

        if ($session->steps) {
            return response()->json([
                'steps'       => $session->steps,
                'description' => $session->description,
            ]);
        }

        $sessions->withCoach(Auth::user()->coach?->personality_prompt)->forUser(Auth::id());
        $steps = $sessions->generateSessionSteps($session);

        if (! $steps) {
            return response()->json(['error' => 'Steps konnten nicht generiert werden.'], 500);
        }

        // Keep the original coaching description intact — the concrete step breakdown is
        // already shown separately in the "Trainingsstruktur" visualization. (Previously
        // this rebuilt the description from the steps and overwrote the coaching text,
        // so loading the structure replaced the athlete-facing prose with "9 min einlaufen…".)
        $update = ['steps' => $steps];

        // Die Struktur folgt der Beschreibung. Ergibt sie eine andere Dauer als
        // die Einheit, war die Zahl daneben falsch — nicht die Struktur. Vorher
        // wurde die Differenz stillschweigend dem Auslaufen abgezogen, und der
        // Athlet las "10 min auslaufen" und fand 7.
        $stepMinutes = SessionContentService::stepsTotalMinutes($steps);

        if ($stepMinutes > 0 && $stepMinutes !== (int) $session->duration_min) {
            $update['duration_min'] = $stepMinutes;

            // Distanz mitziehen, sonst behauptet die Einheit eine Pace, die
            // niemand laeuft.
            if ($session->duration_min > 0 && $session->distance_km > 0) {
                $update['distance_km'] = round($session->distance_km * $stepMinutes / $session->duration_min, 1);
            }
        }

        $session->update($update);

        return response()->json([
            'steps'       => $steps,
            'description' => $session->description,
        ]);
    }

    /**
     * Adjust a planned session harder or softer via AI.
     */
    public function adjustIntensity(Request $request, TrainingSession $session, CoachingTextService $text)
    {
        abort_if($session->user_id !== Auth::id(), 403);
        abort_if($session->status !== 'planned', 422);

        $request->validate([
            'direction' => 'required|in:harder,softer',
        ]);

        $user    = Auth::user();
        $rp      = $user->runnerProfile;
        $profile = $rp ? [
            'threshold_speed'      => PaceFormat::fromMinutes($rp->threshold_speed),
            'threshold_heart_rate' => $rp->threshold_heart_rate,
            'max_heart_rate'       => $rp->max_heart_rate,
        ] : null;

        $wb = $user->wellbeingEntries()->whereDate('date', now()->toDateString())->first();
        $wellbeing = $wb ? [
            'energy_level'    => $wb->energy_level,
            'muscle_soreness' => $wb->muscle_soreness,
        ] : null;

        $current = [
            'type'         => $session->type,
            'title'        => $session->title,
            'description'  => $session->description,
            'distance_km'  => $session->distance_km,
            'duration_min' => $session->duration_min,
            'pace_target'  => $session->pace_target,
            'zone'         => $session->zone,
            'intensity'    => $session->intensity,
        ];

        $text->withCoach($user->coach?->personality_prompt)->forUser(Auth::id());
        $adjusted = $text->adjustTodayRecommendation($current, $request->direction, $profile, $wellbeing);

        if (! $adjusted) {
            return response()->json(['error' => 'Anpassung fehlgeschlagen. Bitte versuche es erneut.'], 500);
        }

        $session->update(array_intersect_key($adjusted, array_flip([
            'type', 'title', 'description', 'distance_km',
            'duration_min', 'pace_target', 'zone', 'intensity',
        ])));

        return response()->json(['session' => $this->formatSession($session->fresh())]);
    }

    /**
     * AI-adjust a single session based on today's wellbeing.
     */
    public function adjust(TrainingSession $session, SessionContentService $sessions)
    {
        abort_if($session->user_id !== Auth::id(), 403);
        abort_if($session->status !== 'planned', 422);

        $wellbeing = Auth::user()->wellbeingEntries()
            ->where('date', now()->toDateString())
            ->first();

        if (! $wellbeing) {
            return response()->json([
                'error' => 'Keine Tagesform für heute eingetragen. Füge zuerst einen Eintrag hinzu.',
            ], 422);
        }

        $sessions->withCoach(Auth::user()->coach?->personality_prompt)->forUser(Auth::id());
        $adjusted = $sessions->adjustSessionForWellbeing($session->toArray(), $wellbeing);

        if (! $adjusted) {
            return response()->json(['error' => 'Anpassung fehlgeschlagen. Bitte versuche es erneut.'], 500);
        }

        $session->update(array_intersect_key($adjusted, array_flip([
            'type', 'title', 'description', 'distance_km',
            'duration_min', 'pace_target', 'zone', 'intensity',
        ])));

        return response()->json(['session' => $this->formatSession($session->fresh())]);
    }

    /**
     * Eine Einheit per Knopf in den Garmin-Kalender — ueber die Training API.
     *
     * Bis zum 28.10.2026 gab es daneben den alten Login ueber den Python-
     * Fit-Service (garth, Passwort im Formular). Er ist mit Schritt 6 der
     * Garmin-Integration weggefallen; ohne offizielle Verbindung gibt es
     * keinen Weg auf die Uhr.
     */
    public function sendToGarmin(TrainingSession $session)
    {
        abort_if($session->user_id !== Auth::id(), 403);

        if (! Auth::user()->sendsWorkoutsViaGarminApi()) {
            return response()->json(['error' => 'Garmin ist nicht verbunden. Bitte im Profil unter Verbindungen verbinden.'], 422);
        }

        return $this->sendViaTrainingApi($session);
    }

    /**
     * Eine Einheit per Knopf in den Garmin-Kalender. Danach pflegt der
     * Abgleich sie mit — aendert der Coach sie, zieht die Uhr nach.
     */
    private function sendViaTrainingApi(TrainingSession $session)
    {
        if (! \App\Services\Watch\AppleWorkoutWriter::supports($session)) {
            return response()->json(['error' => 'Auf die Garmin-Uhr gehen nur Läufe. Radeinheiten gehen zu Zwift.'], 422);
        }

        try {
            app(\App\Services\Garmin\GarminWorkoutSync::class)->push($session);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            return response()->json(['error' => self::garminApiError($e->response->status())], 422);
        } catch (\Throwable $e) {
            Log::warning('Garmin Training API: Senden fehlgeschlagen', ['session_id' => $session->id, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'Garmin hat das Workout nicht angenommen. Bitte später noch einmal versuchen.'], 503);
        }

        return response()->json(['success' => true]);
    }

    /** Was ein Fehler der Training API fuer den Athleten heisst (Spezifikation 3.7). */
    public static function garminApiError(int $status): string
    {
        return match ($status) {
            401     => 'Garmin kennt die Verbindung nicht mehr. Bitte im Profil neu verbinden.',
            403, 412 => 'Zone3 darf keine Workouts an Garmin senden. Bitte in Garmin Connect die Freigabe „Workouts“ einschalten.',
            429     => 'Garmin nimmt heute keine weiteren Workouts an. Bitte morgen noch einmal versuchen.',
            default => 'Garmin hat das Workout nicht angenommen (HTTP ' . $status . ').',
        };
    }

    /**
     * Replace a planned session's content with a workout from the user's library.
     *
     * Denselben Weg gibt es vom Dashboard aus („Einheit tauschen"), und beide
     * muessen dasselbe tun. Hier stand frueher `steps => null`: die Struktur
     * des Workouts wurde verworfen und beim naechsten Aufruf vom Modell neu
     * erfunden — aus „5× 500 m" wurde, was das Modell fuer richtig hielt.
     * `WorkoutPaceResolver` uebersetzt die Bloecke stattdessen direkt, mit den
     * Paces dieses Athleten und mit den Strecken, die drinstehen.
     */
    public function applyWorkout(Request $request, TrainingSession $session, WorkoutPaceResolver $resolver)
    {
        abort_if($session->user_id !== Auth::id(), 403);
        abort_if($session->status !== 'planned', 422);

        $request->validate([
            'workout_id' => 'required|integer|exists:workouts,id',
        ]);

        $workout = \App\Models\Workout::findOrFail($request->workout_id);
        abort_if($workout->user_id !== Auth::id(), 403);

        $resolved = $resolver->resolve($workout, Auth::user()->runnerProfile);

        $session->update([
            'title'        => $workout->name,
            'description'  => $workout->description,
            'type'         => $workout->type,
            'distance_km'  => $resolved['distance_km'] ?? $workout->estimated_distance_km,
            'duration_min' => $resolved['duration_min'] ?: $workout->estimated_duration_min,
            'pace_target'  => $resolved['pace_target'],
            'zone'         => $resolved['zone'],
            'steps'        => $resolved['steps'],
            // Die Verpflegungstipps beschrieben die alte Einheit.
            'nutrition_tips' => null,
            // Der Athlet hat sich das ausgesucht — eine Neuberechnung des
            // Plans darf es nicht stillschweigend wieder wegnehmen.
            'pinned_at'      => now(),
        ]);

        $workout->increment('times_used');
        $workout->update(['last_used_at' => now()]);

        return response()->json(['session' => $this->formatSession($session->fresh())]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function formatSession(TrainingSession $s): array
    {
        return [
            'id'           => $s->id,
            'planned_date' => $s->planned_date->format('Y-m-d'),
            'type'         => $s->type,
            // Die Sportart, damit die Oberflaeche "Schwimmen" zeigen kann
            // und nicht nur "Alternativtraining".
            'sport_label'  => $s->isRun() ? null : $s->sportLabel(),
            'title'        => $s->title,
            'description'  => $s->description,
            'distance_km'  => $s->distance_km,
            'duration_min' => $s->duration_min,
            'pace_target'  => $s->pace_target,
            'zone'         => $s->zone,
            'intensity'    => $s->intensity,
            'status'       => $s->status,
            'skip_reason'  => $s->skip_reason,
            'sort_order'   => $s->sort_order,
            'activity_id'      => $s->activity_id,
            'rating'           => $s->rating,
            'effort_perceived' => $s->effort_perceived,
            'feeling_notes'    => $s->feeling_notes,
            'coach_review'     => $s->coach_review,
            'review_question'  => $s->review_question,
            'review_options'   => $s->review_options,
            'review_feedback'  => $s->review_feedback,
            'nutrition_tips'   => $s->nutrition_tips,
            'exercises'        => $s->exercises,
        ];
    }
}
