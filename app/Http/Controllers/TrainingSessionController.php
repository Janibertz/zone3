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

        $session->update($request->only(['rating', 'effort_perceived', 'feeling_notes']));

        return response()->json(['session' => $this->formatSession($session->fresh())]);
    }

    /**
     * Generate AI nutrition tips for a session.
     */
    public function nutritionTips(TrainingSession $session, OpenAIService $openAI)
    {
        abort_if($session->user_id !== Auth::id(), 403);

        $tips = $openAI->generateNutritionTips([
            'type'         => $session->type,
            'title'        => $session->title,
            'distance_km'  => $session->distance_km,
            'duration_min' => $session->duration_min,
            'pace_target'  => $session->pace_target,
            'intensity'    => $session->intensity,
            'is_race'      => $session->type === 'race' ? 'Ja' : 'Nein',
        ]);

        if (! $tips) {
            return response()->json(['error' => 'Tipps konnten nicht geladen werden.'], 500);
        }

        return response()->json($tips);
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
     * Download the session as a Garmin-compatible FIT workout file.
     */
    public function download(TrainingSession $session)
    {
        abort_if($session->user_id !== Auth::id(), 403);

        $steps    = $this->computeWorkoutSteps($session);
        $fit      = $this->generateFit($session, $steps);
        $filename = Str::slug($session->title ?: 'training')
            . '-' . $session->planned_date->format('Y-m-d')
            . '.fit';

        return response($fit, 200, [
            'Content-Type'        => 'application/vnd.ant.fit',
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
        // TCX v2 schema: Name_t max 15 chars, ScheduledOn is xs:date (no time)
        $workoutName = htmlspecialchars(mb_substr($session->title ?: 'Training', 0, 15), ENT_XML1);
        $scheduledOn = $session->planned_date->format('Y-m-d');
        $notes       = htmlspecialchars($session->description ?: '', ENT_XML1);
        $stepsXml    = '';

        foreach ($steps as $i => $step) {
            $sid      = $i + 1;
            // Step Name_t also max 15 chars; Step_t has no <Notes> element in schema
            $stepName = htmlspecialchars(mb_substr($step['name'], 0, 15), ENT_XML1);
            $meters   = max(100, $step['meters']);

            // Speed target ±5 % — use None_t if no pace set
            if (!empty($step['speedMps']) && $step['speedMps'] > 0) {
                $lo = number_format($step['speedMps'] * 0.95, 4, '.', '');
                $hi = number_format($step['speedMps'] * 1.05, 4, '.', '');
                $targetXml = "          <Target xsi:type=\"Speed_t\">\n"
                    . "            <SpeedZone xsi:type=\"CustomSpeedZone_t\">\n"
                    . "              <LowInMetersPerSecond>{$lo}</LowInMetersPerSecond>\n"
                    . "              <HighInMetersPerSecond>{$hi}</HighInMetersPerSecond>\n"
                    . "            </SpeedZone>\n"
                    . "          </Target>\n";
            } else {
                $targetXml = "          <Target xsi:type=\"None_t\"/>\n";
            }

            $stepsXml .= "        <Step xsi:type=\"Step_t\">\n"
                . "          <StepId>{$sid}</StepId>\n"
                . "          <Name>{$stepName}</Name>\n"
                . "          <Duration xsi:type=\"Distance_t\">\n"
                . "            <Meters>{$meters}</Meters>\n"
                . "          </Duration>\n"
                . "          <Intensity>Active</Intensity>\n"
                . $targetXml
                . "        </Step>\n";
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
{$stepsXml}      <ScheduledOn>{$scheduledOn}</ScheduledOn>
      <Notes>{$notes}</Notes>
    </Workout>
  </Workouts>
</TrainingCenterDatabase>
XML;
    }

    // ── FIT generator ────────────────────────────────────────────────────────

    /**
     * Generate a binary Garmin FIT workout file.
     * Spec: https://developer.garmin.com/fit/protocol/
     */
    private function generateFit(TrainingSession $session, array $steps): string
    {
        $fitEpoch = mktime(0, 0, 0, 12, 31, 1989); // FIT epoch
        $now      = time() - $fitEpoch;
        $numSteps = count($steps);
        $data     = '';

        // ── file_id (mesg 0): type, manufacturer, time_created ──
        $data .= $this->fitDef(0, 0, [[0,1,0],[1,2,0x84],[4,4,0x86]]);
        $data .= chr(0)
               . pack('C', 5)       // type = workout
               . pack('v', 255)     // manufacturer = development
               . pack('V', $now);   // time_created

        // ── workout (mesg 26): sport, num_valid_steps, wkt_name ──
        $data .= $this->fitDef(1, 26, [[4,1,0],[6,2,0x84],[8,16,0x07]]);
        $wktName = str_pad(substr($session->title ?: 'Training', 0, 15) . "\x00", 16, "\x00");
        $data .= chr(1)
               . pack('C', 1)           // sport = running
               . pack('v', $numSteps)   // num_valid_steps
               . $wktName;

        // ── workout_step (mesg 27) definition ──
        $data .= $this->fitDef(2, 27, [
            [254, 2, 0x84], // message_index
            [0,  16, 0x07], // wkt_step_name
            [1,   1, 0x00], // duration_type
            [2,   4, 0x86], // duration_value (cm for distance)
            [3,   1, 0x00], // target_type
            [4,   4, 0x86], // target_value
            [5,   4, 0x86], // custom_target_low (mm/s)
            [6,   4, 0x86], // custom_target_high (mm/s)
            [7,   1, 0x00], // intensity (0=active,2=warmup,3=cooldown)
        ]);

        foreach ($steps as $i => $step) {
            $stepName  = str_pad(substr($step['name'], 0, 15) . "\x00", 16, "\x00");
            $meters    = max(100, $step['meters']);
            $distanceCm = $meters * 100;

            // intensity: first step=warmup, last=cooldown, rest=active
            if ($i === 0) $intensity = 2;
            elseif ($i === $numSteps - 1) $intensity = 3;
            else $intensity = 0;

            // speed target in mm/s
            if (!empty($step['speedMps']) && $step['speedMps'] > 0) {
                $targetType = 0; // speed
                $targetVal  = 0;
                $targetLo   = (int) round($step['speedMps'] * 0.95 * 1000);
                $targetHi   = (int) round($step['speedMps'] * 1.05 * 1000);
            } else {
                $targetType = 2; // open
                $targetVal  = 0;
                $targetLo   = 0;
                $targetHi   = 0;
            }

            $data .= chr(2)
                   . pack('v', $i)           // message_index
                   . $stepName
                   . pack('C', 1)            // duration_type = distance
                   . pack('V', $distanceCm)
                   . pack('C', $targetType)
                   . pack('V', $targetVal)
                   . pack('V', $targetLo)
                   . pack('V', $targetHi)
                   . pack('C', $intensity);
        }

        // ── header ──
        $dataSize  = strlen($data);
        $headerRaw = pack('C', 14) . pack('C', 0x20) . pack('v', 2132) . pack('V', $dataSize) . '.FIT';
        $headerCrc = $this->fitCrc($headerRaw);
        $header    = $headerRaw . pack('v', $headerCrc);

        $fileCrc = $this->fitCrc($data);
        return $header . $data . pack('v', $fileCrc);
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
        ];
    }
}
