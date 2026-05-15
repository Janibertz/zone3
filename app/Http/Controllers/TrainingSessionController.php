<?php

namespace App\Http\Controllers;

use App\Models\TrainingSession;
use App\Services\OpenAIService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class TrainingSessionController extends Controller
{
    /**
     * Mark a session as completed.
     */
    public function complete(TrainingSession $session)
    {
        abort_if($session->user_id !== Auth::id(), 403);
        abort_if($session->status !== 'planned', 422);

        $session->update(['status' => 'completed']);

        return response()->json(['session' => $this->formatSession($session)]);
    }

    /**
     * Mark a session as skipped.
     */
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

        return response()->json(['session' => $this->formatSession($session)]);
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
     * Return AI nutrition tips for a session — served from DB cache, generated on first request.
     */
    public function nutritionTips(TrainingSession $session, OpenAIService $openAI)
    {
        abort_if($session->user_id !== Auth::id(), 403);

        // Return cached tips if available
        if ($session->nutrition_tips) {
            return response()->json($session->nutrition_tips);
        }

        $openAI->withCoach(Auth::user()->coach?->personality_prompt)->forUser(Auth::id());
        $tips = $openAI->generateNutritionTips([
            'type'         => $session->type,
            'title'        => $session->title,
            'distance_km'  => $session->distance_km,
            'duration_min' => $session->duration_min,
            'pace_target'  => $session->pace_target,
            'intensity'    => $session->intensity,
            'is_race'      => $session->type === 'race_prep' ? 'Ja' : 'Nein',
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
    public function sessionSteps(TrainingSession $session, OpenAIService $openAI)
    {
        abort_if($session->user_id !== Auth::id(), 403);
        abort_if(in_array($session->type, ['rest', 'race_prep']), 422);

        if ($session->steps) {
            return response()->json($session->steps);
        }

        $openAI->withCoach(Auth::user()->coach?->personality_prompt)->forUser(Auth::id());
        $steps = $openAI->generateSessionSteps($session);

        if (! $steps) {
            return response()->json(['error' => 'Steps konnten nicht generiert werden.'], 500);
        }

        $session->update(['steps' => $steps]);

        return response()->json($steps);
    }

    /**
     * Adjust a planned session harder or softer via AI.
     */
    public function adjustIntensity(Request $request, TrainingSession $session, OpenAIService $openAI)
    {
        abort_if($session->user_id !== Auth::id(), 403);
        abort_if($session->status !== 'planned', 422);

        $request->validate([
            'direction' => 'required|in:harder,softer',
        ]);

        $user    = Auth::user();
        $rp      = $user->runnerProfile;
        $profile = $rp ? [
            'threshold_speed'      => sprintf('%d:%02d', (int) $rp->threshold_speed, (int)(($rp->threshold_speed - (int) $rp->threshold_speed) * 60)),
            'threshold_heart_rate' => $rp->threshold_heart_rate,
            'max_heart_rate'       => $rp->max_heart_rate,
        ] : null;

        $wb = $user->wellbeingEntries()->where('date', now()->toDateString())->first();
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

        $openAI->withCoach($user->coach?->personality_prompt)->forUser(Auth::id());
        $adjusted = $openAI->adjustTodayRecommendation($current, $request->direction, $profile, $wellbeing);

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
    public function adjust(TrainingSession $session, OpenAIService $openAI)
    {
        abort_if($session->user_id !== Auth::id(), 403);
        abort_if($session->status !== 'planned', 422);

        $wellbeing = Auth::user()->wellbeingEntries()
            ->where('date', now()->toDateString())
            ->first();

        if (! $wellbeing) {
            return response()->json([
                'error' => 'Kein Wellbeing-Eintrag für heute. Füge zuerst einen Eintrag hinzu.',
            ], 422);
        }

        $openAI->withCoach(Auth::user()->coach?->personality_prompt)->forUser(Auth::id());
        $adjusted = $openAI->adjustSessionForWellbeing($session->toArray(), $wellbeing);

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
     * Download the session as a Garmin-compatible FIT workout file.
     * Tries Python FIT service first, falls back to PHP generator.
     */
    public function download(TrainingSession $session)
    {
        abort_if($session->user_id !== Auth::id(), 403);

        $steps    = $this->computeWorkoutSteps($session);
        $fit      = $this->generateFitViaService($session, $steps) ?? $this->generateFit($session, $steps);
        $filename = Str::slug($session->title ?: 'training')
            . '-' . $session->planned_date->format('Y-m-d')
            . '.fit';

        return response($fit, 200, [
            'Content-Type'        => 'application/vnd.ant.fit',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Send the session as a structured workout directly to Garmin Connect.
     * Uses saved garth session tokens if available; otherwise requires fresh credentials.
     * Saves session tokens after a successful fresh login so future sends need no credentials.
     */
    public function sendToGarmin(Request $request, TrainingSession $session)
    {
        abort_if($session->user_id !== Auth::id(), 403);

        $user             = Auth::user();
        $hasSavedSession  = !empty($user->garmin_session);

        if (! $hasSavedSession) {
            $request->validate([
                'email'    => 'required|email',
                'password' => 'required|string|min:1',
            ]);
        }

        $aiSteps     = $session->steps;
        $garminSteps = $aiSteps
            ? $this->convertAiStepsForGarmin($aiSteps)
            : $this->convertComputedStepsForGarmin($this->computeWorkoutSteps($session));

        $result = $this->sendToGarminViaService(
            $session,
            $garminSteps,
            $hasSavedSession ? null          : $request->email,
            $hasSavedSession ? null          : $request->password,
            $hasSavedSession ? $user->garmin_session : null,
        );

        if ($result === null) {
            return response()->json(['error' => 'FIT-Service nicht verfügbar.'], 503);
        }

        // Session expired → clear stored tokens so frontend shows login form again
        if (isset($result['error']) && $result['error'] === 'session_expired') {
            $user->update(['garmin_email' => null, 'garmin_session' => null]);
            return response()->json(['error' => 'session_expired'], 401);
        }

        // Save session tokens returned from a fresh credential login
        if (!empty($result['session'])) {
            $user->update([
                'garmin_email'   => $request->email,
                'garmin_session' => $result['session'],
            ]);
        }
        unset($result['session']);

        return response()->json($result);
    }

    /**
     * Remove stored Garmin Connect session tokens for the current user.
     */
    public function garminDisconnect()
    {
        Auth::user()->update(['garmin_email' => null, 'garmin_session' => null]);
        return response()->json(['success' => true]);
    }

    /**
     * Convert AI-generated session steps to Garmin payload format.
     * AI format: {type, label, duration_min, pace_target, zone, repetitions}
     * Garmin format: {name, step_type, duration_sec, speedMps, repetitions}
     */
    private function convertAiStepsForGarmin(array $aiSteps): array
    {
        return array_map(function (array $step) {
            $durationSec = isset($step['duration_min'])
                ? (int) round((float) $step['duration_min'] * 60)
                : null;

            $speedMps = null;
            if (!empty($step['pace_target']) && $step['pace_target'] !== 'null') {
                $parts = explode(':', (string) $step['pace_target']);
                if (count($parts) === 2) {
                    $secPerKm = (int) $parts[0] * 60 + (int) $parts[1];
                    $speedMps = $secPerKm > 0 ? round(1000 / $secPerKm, 5) : null;
                }
            }

            return [
                'name'         => $step['label'] ?? ucfirst($step['type'] ?? 'Step'),
                'step_type'    => $step['type'] ?? 'active',
                'duration_sec' => $durationSec,
                'meters'       => null,
                'speedMps'     => $speedMps,
                'repetitions'  => $step['repetitions'] ?? null,
            ];
        }, $aiSteps);
    }

    /**
     * Convert computeWorkoutSteps() flat array to Garmin payload format.
     */
    private function convertComputedStepsForGarmin(array $computedSteps): array
    {
        $n = count($computedSteps);
        $result = [];
        foreach ($computedSteps as $i => $step) {
            if ($i === 0) {
                $type = 'warmup';
            } elseif ($i === $n - 1) {
                $type = 'cooldown';
            } else {
                $type = 'active';
            }
            $result[] = [
                'name'         => $step['name'],
                'step_type'    => $type,
                'meters'       => $step['meters'],
                'duration_sec' => null,
                'speedMps'     => $step['speedMps'],
                'repetitions'  => null,
            ];
        }
        return $result;
    }

    private function sendToGarminViaService(
        TrainingSession $session,
        array $garminSteps,
        ?string $email,
        ?string $password,
        ?string $savedSession,
    ): ?array {
        $serviceUrl = config('services.fit.service_url');
        if (! $serviceUrl) {
            return null;
        }

        $payload = [
            'name'        => mb_substr($session->title ?: 'Training', 0, 50, 'UTF-8'),
            'description' => $session->description ?: null,
            'date'        => $session->planned_date->format('Y-m-d'),
            'sport'       => 'running',
            'steps'       => $garminSteps,
        ];

        if ($savedSession) {
            $payload['garmin_session'] = $savedSession;
        } else {
            $payload['garmin_email']    = $email;
            $payload['garmin_password'] = $password;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(30)
                ->post(rtrim($serviceUrl, '/') . '/send-to-garmin', $payload);

            $json = $response->json() ?: [];

            if (! isset($json['success']) && isset($json['detail'])) {
                $detail = $json['detail'];
                if ($detail === 'mfa_required')            return ['error' => 'mfa_required'];
                if ($detail === 'session_expired')         return ['error' => 'session_expired'];
                if (str_starts_with($detail, 'login_failed:'))
                    return ['error' => 'Falsche Zugangsdaten. Bitte E-Mail und Passwort prüfen.'];
                return ['error' => $detail];
            }

            return $json ?: ['error' => 'Leere Antwort'];
        } catch (\Exception $e) {
            \Log::error('Garmin Connect: service error: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    public function downloadTcx(TrainingSession $session)
    {
        abort_if($session->user_id !== Auth::id(), 403);

        $steps    = $this->computeWorkoutSteps($session);
        $tcx      = $this->generateTcx($session, $steps);
        $filename = Str::slug($session->title ?: 'training')
            . '-' . $session->planned_date->format('Y-m-d')
            . '.tcx';

        return response($tcx, 200, [
            'Content-Type'        => 'application/vnd.garmin.tcx+xml',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // ── Workout step computation ──────────────────────────────────────────────

    /**
     * Break a session into Aufwärmen / Hauptteil / Auslaufen steps.
     * Returns array of ['name', 'label', 'meters', 'pace', 'speedMps'].
     */
    private function computeWorkoutSteps(TrainingSession $session): array
    {
        if ($session->type === 'rest') {
            return [];
        }

        $distKm          = $session->distance_km ?: 5.0;
        $paceSecPerKm    = $this->parsePaceToSeconds($session->pace_target);
        $easySecPerKm    = $paceSecPerKm ? $paceSecPerKm + 60 : null; // 1 min/km slower

        $configs = [
            'easy_run'  => [
                'warmupFrac' => 0.10, 'warmupMax' => 1.0,
                'coolFrac'   => 0.10, 'coolMax'   => 1.0,
                'warmupName' => 'Aufwärmen',   'warmupLabel' => 'Leichtes Einlaufen',
                'mainName'   => 'Hauptteil',   'mainLabel'   => 'Lockeres Dauertempo',
                'coolName'   => 'Auslaufen',   'coolLabel'   => 'Leichtes Auslaufen',
            ],
            'tempo_run' => [
                'warmupFrac' => 0.25, 'warmupMax' => 2.0,
                'coolFrac'   => 0.12, 'coolMax'   => 1.0,
                'warmupName' => 'Aufwärmen',   'warmupLabel' => 'Lockeres Einlaufen',
                'mainName'   => 'Hauptteil',   'mainLabel'   => 'Tempodauerlauf',
                'coolName'   => 'Auslaufen',   'coolLabel'   => 'Lockeres Auslaufen',
            ],
            'interval'  => [
                'warmupFrac' => 0.20, 'warmupMax' => 2.0,
                'coolFrac'   => 0.10, 'coolMax'   => 1.0,
                'warmupName' => 'Aufwärmen',   'warmupLabel' => 'Lockeres Einlaufen',
                'mainName'   => 'Intervalle',  'mainLabel'   => 'Intervallarbeit (Details in Beschreibung)',
                'coolName'   => 'Auslaufen',   'coolLabel'   => 'Lockeres Auslaufen',
            ],
            'long_run'  => [
                'warmupFrac' => 0.05, 'warmupMax' => 1.0,
                'coolFrac'   => 0.05, 'coolMax'   => 1.0,
                'warmupName' => 'Einlaufen',   'warmupLabel' => 'Leichtes Einlaufen',
                'mainName'   => 'Hauptteil',   'mainLabel'   => 'Langer gleichmäßiger Lauf',
                'coolName'   => 'Auslaufen',   'coolLabel'   => 'Lockeres Auslaufen',
            ],
            'race_prep' => [
                'warmupFrac' => 0.30, 'warmupMax' => 2.0,
                'coolFrac'   => 0.15, 'coolMax'   => 1.0,
                'warmupName' => 'Aufwärmen',   'warmupLabel' => 'Lockeres Einlaufen + Strides',
                'mainName'   => 'Hauptteil',   'mainLabel'   => 'Renntempo-Abschnitte',
                'coolName'   => 'Auslaufen',   'coolLabel'   => 'Lockeres Auslaufen',
            ],
        ];

        $cfg = $configs[$session->type] ?? $configs['easy_run'];

        $warmupKm  = min($cfg['warmupMax'], $distKm * $cfg['warmupFrac']);
        $cooldownKm = min($cfg['coolMax'],  $distKm * $cfg['coolFrac']);
        $mainKm    = max(0.0, $distKm - $warmupKm - $cooldownKm);

        $mainSpeedMps = $paceSecPerKm ? round(1000 / $paceSecPerKm, 5) : null;
        $easySpeedMps = $easySecPerKm  ? round(1000 / $easySecPerKm, 5) : null;

        return [
            [
                'name'     => $cfg['warmupName'],
                'label'    => $cfg['warmupLabel'],
                'meters'   => (int) round($warmupKm * 1000),
                'pace'     => $easySecPerKm ? $this->secPerKmToPaceString($easySecPerKm) : null,
                'speedMps' => $easySpeedMps,
            ],
            [
                'name'     => $cfg['mainName'],
                'label'    => $cfg['mainLabel'],
                'meters'   => (int) round($mainKm * 1000),
                'pace'     => $session->pace_target,
                'speedMps' => $mainSpeedMps,
            ],
            [
                'name'     => $cfg['coolName'],
                'label'    => $cfg['coolLabel'],
                'meters'   => (int) round($cooldownKm * 1000),
                'pace'     => $easySecPerKm ? $this->secPerKmToPaceString($easySecPerKm) : null,
                'speedMps' => $easySpeedMps,
            ],
        ];
    }

    // ── TCX generator ─────────────────────────────────────────────────────────

    private function generateTcx(TrainingSession $session, array $steps): string
    {
        // Garmin TCX v2: Name_t max 15 chars (schema enforced)
        // - <ScheduledOn> is schema-valid but causes Garmin Connect import rejection → omit
        // - <Notes> must be omitted entirely when empty (empty element fails Connect validation)
        // - <Intensity> only accepts "Active" or "Resting" per schema
        $workoutName = htmlspecialchars(mb_substr($session->title ?: 'Training', 0, 15, 'UTF-8'), ENT_XML1, 'UTF-8');
        $stepsXml    = '';

        foreach ($steps as $i => $step) {
            $sid      = $i + 1;
            $stepName = htmlspecialchars(mb_substr($step['name'], 0, 15, 'UTF-8'), ENT_XML1, 'UTF-8');
            $meters   = max(100, $step['meters']);

            // Only the main step gets a speed target; warmup/cooldown use None_t
            if (!empty($step['speedMps']) && $step['speedMps'] > 0) {
                $lo = number_format($step['speedMps'] * 0.95, 4, '.', '');
                $hi = number_format($step['speedMps'] * 1.05, 4, '.', '');
                $targetXml = "        <Target xsi:type=\"Speed_t\">\n"
                    . "          <SpeedZone xsi:type=\"CustomSpeedZone_t\">\n"
                    . "            <LowInMetersPerSecond>{$lo}</LowInMetersPerSecond>\n"
                    . "            <HighInMetersPerSecond>{$hi}</HighInMetersPerSecond>\n"
                    . "          </SpeedZone>\n"
                    . "        </Target>\n";
            } else {
                $targetXml = "        <Target xsi:type=\"None_t\"/>\n";
            }

            $stepsXml .= "      <Step xsi:type=\"Step_t\">\n"
                . "        <StepId>{$sid}</StepId>\n"
                . "        <Name>{$stepName}</Name>\n"
                . "        <Duration xsi:type=\"Distance_t\">\n"
                . "          <Meters>{$meters}</Meters>\n"
                . "        </Duration>\n"
                . "        <Intensity>Active</Intensity>\n"
                . $targetXml
                . "      </Step>\n";
        }

        $notesTag = '';
        if (!empty($session->description)) {
            $notes    = htmlspecialchars($session->description, ENT_XML1, 'UTF-8');
            $notesTag = "      <Notes>{$notes}</Notes>\n";
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<TrainingCenterDatabase
  xmlns="http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2 http://www.garmin.com/xmlschemas/TrainingCenterDatabasev2.xsd">
  <Workouts>
    <Workout Sport="Running">
      <Name>{$workoutName}</Name>
{$stepsXml}{$notesTag}    </Workout>
  </Workouts>
</TrainingCenterDatabase>
XML;
    }

    // ── FIT generator ────────────────────────────────────────────────────────

    /**
     * Generate FIT via the Python fit-service microservice.
     * Returns null if the service URL is not configured or the request fails.
     */
    private function generateFitViaService(TrainingSession $session, array $steps): ?string
    {
        $serviceUrl = config('services.fit.service_url');
        if (! $serviceUrl) {
            \Log::warning('FIT: FIT_SERVICE_URL not set, using PHP fallback');
            return null;
        }

        \Log::warning('FIT: calling Python service', ['url' => $serviceUrl]);

        $payload = [
            'name'  => mb_substr($session->title ?: 'Training', 0, 15, 'UTF-8'),
            'steps' => array_map(fn ($s) => [
                'name'     => $s['name'],
                'meters'   => $s['meters'],
                'speedMps' => $s['speedMps'],
            ], $steps),
        ];

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->post(rtrim($serviceUrl, '/') . '/generate-fit', $payload);

            if ($response->successful() && strlen($response->body()) > 14) {
                \Log::warning('FIT: Python service succeeded', ['bytes' => strlen($response->body())]);
                return $response->body();
            }

            \Log::warning('FIT: service returned unexpected response', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 500),
            ]);
        } catch (\Exception $e) {
            \Log::error('FIT: service error: ' . $e->getMessage());
        }

        \Log::warning('FIT: falling back to PHP generator');
        return null;
    }

    /**
     * Generate a binary Garmin FIT workout file (PHP fallback).
     * Spec: https://developer.garmin.com/fit/protocol/
     */
    private function generateFit(TrainingSession $session, array $steps): string
    {
        // gmmktime ensures UTC regardless of server timezone — critical for FIT epoch
        $fitEpoch = gmmktime(0, 0, 0, 12, 31, 1989);
        $now      = time() - $fitEpoch;
        $numSteps = count($steps);
        $data     = '';

        // ── file_id (mesg 0): type=workout, manufacturer=development, time_created ──
        $data .= $this->fitDef(0, 0, [[0,1,0x00],[1,2,0x84],[4,4,0x86]]);
        $data .= chr(0)
               . pack('C', 5)       // type = workout (5)
               . pack('v', 255)     // manufacturer = development (255)
               . pack('V', $now);   // time_created

        // ── workout (mesg 26): sport=f4(enum), num_valid_steps=f6(uint16), wkt_name=f8(str) ──
        $data .= $this->fitDef(1, 26, [[4,1,0x00],[6,2,0x84],[8,16,0x07]]);
        $wktName = $this->fitString(mb_substr($session->title ?: 'Training', 0, 15, 'UTF-8'), 16);
        $data .= chr(1)
               . pack('C', 1)          // sport = running       (field 4, enum)
               . pack('v', $numSteps)  // num_valid_steps       (field 6, uint16)
               . $wktName;             // wkt_name              (field 8, string)

        // ── workout_step (mesg 27) definition ──
        $data .= $this->fitDef(2, 27, [
            [254, 2, 0x84], // message_index  (uint16)
            [0,  16, 0x07], // wkt_step_name  (string, 16 bytes incl. null)
            [1,   1, 0x00], // duration_type  (enum: 1=distance)
            [2,   4, 0x86], // duration_value (uint32: meters×100 for distance)
            [3,   1, 0x00], // target_type    (enum: 0=speed, 2=open)
            [4,   4, 0x86], // target_value   (uint32: speed zone id or 0xFFFFFFFF)
            [5,   4, 0x86], // custom_target_value_low  (uint32: mm/s)
            [6,   4, 0x86], // custom_target_value_high (uint32: mm/s)
            [7,   1, 0x00], // intensity      (enum: 0=active, 2=warmup, 3=cooldown)
        ]);

        foreach ($steps as $i => $step) {
            $stepName   = $this->fitString(mb_substr($step['name'], 0, 15, 'UTF-8'), 16);
            $meters     = max(100, $step['meters']);
            $distanceCm = $meters * 100; // FIT distance unit: meters × 100

            // intensity: warmup for first, cooldown for last, active otherwise
            $intensity = match(true) {
                $i === 0                => 2, // warmup
                $i === $numSteps - 1   => 3, // cooldown
                default                => 0, // active
            };

            // speed target (mm/s) — 0xFFFFFFFF = invalid/unused per FIT spec
            if (!empty($step['speedMps']) && $step['speedMps'] > 0) {
                $targetType = 0; // speed with custom range
                $targetVal  = 0xFFFFFFFF; // no predefined zone
                $targetLo   = (int) round($step['speedMps'] * 0.95 * 1000);
                $targetHi   = (int) round($step['speedMps'] * 1.05 * 1000);
            } else {
                $targetType = 2; // open (no target)
                $targetVal  = 0xFFFFFFFF;
                $targetLo   = 0xFFFFFFFF;
                $targetHi   = 0xFFFFFFFF;
            }

            $data .= chr(2)
                   . pack('v', $i)              // message_index
                   . $stepName                  // wkt_step_name
                   . pack('C', 1)               // duration_type = distance
                   . pack('V', $distanceCm)     // duration_value
                   . pack('C', $targetType)     // target_type
                   . pack('V', $targetVal)      // target_value
                   . pack('V', $targetLo)       // custom_target_value_low
                   . pack('V', $targetHi)       // custom_target_value_high
                   . pack('C', $intensity);     // intensity
        }

        // ── header ──
        $dataSize  = strlen($data);
        $headerRaw = pack('C', 14) . pack('C', 0x20) . pack('v', 2132) . pack('V', $dataSize) . '.FIT';
        $headerCrc = $this->fitCrc($headerRaw);
        $header    = $headerRaw . pack('v', $headerCrc);

        $fileCrc = $this->fitCrc($header . $data);
        return $header . $data . pack('v', $fileCrc);
    }

    /** Encode a string as a null-terminated, zero-padded ASCII FIT string field. */
    private function fitString(string $value, int $size): string
    {
        // FIT strings must be ASCII — normalize German umlauts
        $value = str_replace(
            ['ä','ö','ü','Ä','Ö','Ü','ß'],
            ['ae','oe','ue','Ae','Oe','Ue','ss'],
            $value
        );
        // Strip remaining non-ASCII chars, then truncate to size-1 (null terminator)
        $ascii = preg_replace('/[^\x20-\x7E]/', '', $value);
        $bytes = substr($ascii, 0, $size - 1);
        return str_pad($bytes . "\x00", $size, "\x00");
    }

    /** Build a FIT definition message for a local message number. */
    private function fitDef(int $localNum, int $globalNum, array $fields): string
    {
        $msg  = chr(0x40 | $localNum); // definition record header
        $msg .= chr(0);                // reserved
        $msg .= chr(0);                // architecture: little-endian
        $msg .= pack('v', $globalNum);
        $msg .= chr(count($fields));
        foreach ($fields as [$fNum, $fSize, $fBase]) {
            $msg .= chr($fNum) . chr($fSize) . chr($fBase);
        }
        return $msg;
    }

    /** FIT CRC-16 (Garmin variant). */
    private function fitCrc(string $data): int
    {
        $crc = 0;
        $t   = [0x0000,0xCC01,0xD801,0x1400,0xF001,0x3C00,0x2800,0xE401,
                0xA001,0x6C00,0x7800,0xB401,0x5000,0x9C01,0x8801,0x4400];
        for ($i = 0; $i < strlen($data); $i++) {
            $b    = ord($data[$i]);
            $tmp  = $t[$crc & 0xF];
            $crc  = ($crc >> 4) & 0x0FFF;
            $crc ^= $tmp ^ $t[$b & 0xF];
            $tmp  = $t[$crc & 0xF];
            $crc  = ($crc >> 4) & 0x0FFF;
            $crc ^= $tmp ^ $t[($b >> 4) & 0xF];
        }
        return $crc;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function parsePaceToSeconds(?string $pace): ?int
    {
        if (! $pace || $pace === 'null') return null;
        $parts = explode(':', $pace);
        if (count($parts) !== 2) return null;
        return (int) $parts[0] * 60 + (int) $parts[1];
    }

    private function secPerKmToPaceString(int $sec): string
    {
        return sprintf('%d:%02d', intdiv($sec, 60), $sec % 60);
    }

    private function formatSession(TrainingSession $s): array
    {
        return [
            'id'           => $s->id,
            'planned_date' => $s->planned_date->format('Y-m-d'),
            'type'         => $s->type,
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
            'nutrition_tips'   => $s->nutrition_tips,
        ];
    }
}
