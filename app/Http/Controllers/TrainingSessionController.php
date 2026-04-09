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

        $adjusted = $openAI->adjustSessionForWellbeing($session->toArray(), $wellbeing);

        if (! $adjusted) {
            return response()->json(['error' => 'KI-Anpassung fehlgeschlagen. Bitte versuche es erneut.'], 500);
        }

        $session->update(array_intersect_key($adjusted, array_flip([
            'type', 'title', 'description', 'distance_km',
            'duration_min', 'pace_target', 'zone', 'intensity',
        ])));

        return response()->json(['session' => $this->formatSession($session->fresh())]);
    }

    /**
     * Download the session as a Garmin-compatible TCX workout file.
     */
    public function download(TrainingSession $session)
    {
        abort_if($session->user_id !== Auth::id(), 403);

        $steps = $this->computeWorkoutSteps($session);
        $xml   = $this->generateTcx($session, $steps);

        $filename = Str::slug($session->title ?: 'training')
            . '-' . $session->planned_date->format('Y-m-d')
            . '.tcx';

        return response($xml, 200, [
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
        $workoutName = htmlspecialchars($session->title ?: 'Training', ENT_XML1);
        $stepsXml    = '';

        foreach ($steps as $i => $step) {
            $sid       = $i + 1;
            $stepName  = htmlspecialchars($step['name'], ENT_XML1);
            $meters    = max(1, $step['meters']);
            $targetXml = '';

            if ($step['speedMps']) {
                $lo = round($step['speedMps'] * 0.95, 5);
                $hi = round($step['speedMps'] * 1.05, 5);
                $targetXml = "        <Target xsi:type=\"Speed_t\">\n"
                    . "          <SpeedZone xsi:type=\"CustomSpeedZone_t\">\n"
                    . "            <LowInMetersPerSecond>{$lo}</LowInMetersPerSecond>\n"
                    . "            <HighInMetersPerSecond>{$hi}</HighInMetersPerSecond>\n"
                    . "          </SpeedZone>\n"
                    . "        </Target>\n";
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

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<TrainingCenterDatabase
  xmlns="http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2 http://www.garmin.com/xmlschemas/TrainingCenterDatabasev2.xsd">
  <Workouts>
    <Workout Sport="Running">
      <Name>{$workoutName}</Name>
{$stepsXml}    </Workout>
  </Workouts>
</TrainingCenterDatabase>
XML;
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
            'activity_id'  => $s->activity_id,
        ];
    }
}
