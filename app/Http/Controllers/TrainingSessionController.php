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

    /**
     * Die Abschnitte einer Einheit für die Details — ohne Modellaufruf.
     *
     * Bis 29.10.2026 erfand hier ein KI-Aufruf die Struktur beim ersten
     * Öffnen, und sie passte oft nicht zu Dauer und Distanz in der Liste.
     * Neue Einheiten bringen ihren Ablauf aus dem Katalog mit; einer älteren
     * ohne Schritte baut ihn der Katalog jetzt hier nach.
     */
    public function sessionSteps(TrainingSession $session, \App\Services\Run\SessionStructure $structure, SessionContentService $sessions)
    {
        abort_if($session->user_id !== Auth::id(), 403);
        // Strength/core/mobility sessions carry their own "exercises" list.
        abort_if(in_array($session->type, ['rest', 'race_prep', 'strength', 'core', 'mobility']), 422);
        // Completed sessions show real Strava splits — no planned structure.
        abort_if($session->status === 'completed', 422);

        if (! $session->steps) {
            if ($session->type === 'yard_simulation') {
                $session->steps = $sessions->buildYardSimulationSteps($session);
            } elseif (! $structure->apply($session)) {
                return response()->json(['error' => 'Für diese Einheit gibt es keinen Ablauf.'], 422);
            }
        }

        // Aeltere Einheiten tragen noch die Zahl aus dem Wochenbudget —
        // beim ersten Oeffnen wird sie an die Struktur angeglichen.
        $this->syncDistanceWithSteps($session, (array) $session->steps);

        return $this->stepsResponse($session);
    }

    /**
     * Die Distanz der Einheit kommt aus ihren Abschnitten, sobald es welche gibt.
     *
     * Gemeldet: in der Liste „Tempolauf 10,7 km", in den Details 12 km. Die
     * 10,7 sind das Wochenbudget — Minuten mal eine Durchschnittspace, die
     * fuer einen lockeren Lauf stimmt, fuer 25 Minuten an der Schwelle aber
     * nicht. Die Details rechneten im Browser aus den Abschnitten nach und
     * speicherten nichts. Jetzt rechnet der Server einmal, speichert, und
     * Liste, Details, Uhr und Wochensumme lesen dieselbe Zahl.
     */
    private function syncDistanceWithSteps(TrainingSession $session, array $steps): void
    {
        $km = SessionContentService::stepsTotalKm($steps);

        if ($km !== null && abs($km - (float) $session->distance_km) >= 0.1) {
            $session->distance_km = $km;
        }

        if ($session->isDirty()) {
            $session->save();
        }
    }

    private function stepsResponse(TrainingSession $session)
    {
        return response()->json([
            'steps'        => $session->steps,
            'description'  => $session->description,
            'distance_km'  => $session->distance_km,
            'duration_min' => $session->duration_min,
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

        $this->applyAdjusted($session, $adjusted);

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

        $this->applyAdjusted($session, $adjusted);

        return response()->json(['session' => $this->formatSession($session->fresh())]);
    }

    /**
     * Die Antwort des Modells übernehmen — den Ablauf baut der Katalog,
     * mit der Dauer, die das Modell vorgibt.
     */
    private function applyAdjusted(TrainingSession $session, array $adjusted): void
    {
        $session->fill(array_intersect_key($adjusted, array_flip([
            'type', 'title', 'description', 'distance_km',
            'duration_min', 'pace_target', 'zone', 'intensity',
        ])));
        $session->steps = null;
        app(\App\Services\Run\SessionStructure::class)->apply($session, $adjusted['workout'] ?? null);
        $session->save();
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
            // Wie beim Einplanen aus dem Builder: aus einem lockeren Lauf
            // wird mit einem Intervall-Workout keine lockere Einheit.
            'intensity'    => match (true) {
                in_array($workout->type, ['interval', 'tempo_run'], true) => 'high',
                ($resolved['zone'] ?? 2) >= 3                             => 'medium',
                default                                                   => 'low',
            },
            // Der Katalog-Baustein gehoerte zur alten Einheit. Stehen
            // gelassen, zaehlte er in der Rotation als gelaufen.
            'workout_variant' => null,
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
