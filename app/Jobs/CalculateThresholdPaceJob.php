<?php

namespace App\Jobs;

use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\AI\AthleteProfileService;
use App\Services\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use App\Services\PaceFormat;

class CalculateThresholdPaceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 150; // Flex: doppeltes Zeitlimit plus ein Versuch auf Standard

    /** Mehr Aenderung auf einen Schlag ist kein Formzuwachs, sondern ein Ausreisser. */
    private const MAX_JUMP = 0.08;

    /** Ab dieser Aenderung lohnt es, die Zielpaces im Plan neu schreiben zu lassen. */
    private const REPLAN_THRESHOLD = 0.015;

    public function __construct(public readonly int $userId) {}

    public function handle(AthleteProfileService $profiles, WebPushService $webPush): void
    {
        $user = User::find($this->userId);
        if (! $user) return;

        $last20 = $user->activities()
            ->where('type', 'Run')
            ->where('average_speed', '>', 0)
            ->where('distance', '>', 0)
            ->orderByDesc('start_date')
            ->limit(20)
            ->get()
            ->toArray();

        if (empty($last20)) {
            RunnerProfile::where('user_id', $this->userId)
                ->update(['threshold_pace_calculating' => false]);
            return;
        }

        $profile = RunnerProfile::firstOrCreate(
            ['user_id' => $this->userId],
            ['has_completed_setup' => false]
        );

        $result = $profiles->calculateThresholdPaceWithAI($last20, $profile->threshold_heart_rate);

        if ($result === null) {
            $profile->threshold_pace_calculating = false;
            $profile->save();
            return;
        }

        $thresholdPace = $result['pace'];
        $previous      = $profile->threshold_speed;

        // ── Zwei Gruende, den alten Wert zu behalten ─────────────────────
        //
        // Der Wert wanderte bisher ungeprueft in threshold_speed, von dort in
        // die Pace-Zonen und in jede Renn-Prognose. Und weil die Temperatur
        // bei einem Reasoning-Modell nicht durchgereicht wird, gibt es gegen
        // Streuung von Lauf zu Lauf sonst nichts.
        $reject = null;

        if ($previous > 0 && $result['confidence'] === 'low') {
            // Ohne belastbaren Anker die vorhandene Schaetzung austauschen
            // hiesse, Rauschen gegen Rauschen zu tauschen.
            $reject = 'niedrige Konfidenz';
        } elseif ($previous > 0 && abs($thresholdPace - $previous) / $previous > self::MAX_JUMP) {
            // Ueber acht Prozent in einem Schritt ist keine Formentwicklung.
            $reject = sprintf('Sprung um %+.1f %%', (($thresholdPace - $previous) / $previous) * 100);
        }

        if ($reject !== null) {
            Log::info('Threshold pace verworfen', [
                'user_id'    => $this->userId,
                'grund'      => $reject,
                'vorschlag'  => round($thresholdPace, 4),
                'bestand'    => round($previous, 4),
                'confidence' => $result['confidence'],
            ]);

            $profile->threshold_pace_calculating   = false;
            $profile->threshold_pace_calculated_at = now();
            $profile->save();
            return;
        }

        // Der Uebertrag auf 60 Sekunden steckt jetzt in PaceFormat — hier
        // stand er als eigene Fallunterscheidung, in den anderen Kopien nicht.
        $paceFormatted = PaceFormat::fromMinutes($thresholdPace);

        $history   = $profile->threshold_pace_history ?? [];
        $history[] = [
            'date'           => now()->format('d.m.Y'),
            'pace'           => round($thresholdPace, 4),
            'pace_formatted' => $paceFormatted,
            'confidence'     => $result['confidence'],
            'range'          => $result['range'],
            'evidence'       => $result['evidence'],
        ];

        // Was der Athlet bisher als Schwelle sah (aus dem VDOT, siehe
        // RunnerProfile::thresholdDisplay()) — die Push meldet nur, wenn
        // sich diese Zahl ändert.
        $shownBefore = $profile->thresholdDisplay()['pace'] ?? null;

        $profile->threshold_speed              = $thresholdPace;
        $profile->threshold_pace_calculated_at = now();
        $profile->threshold_pace_history       = array_slice($history, -30);
        $profile->pace_zones                   = $profile->calculatePaceZones();
        $profile->threshold_pace_calculating   = false;
        $profile->save();

        // ── Der Plan haengt daran ────────────────────────────────────────
        //
        // Die Pace-Zonen im Profil und die Renn-Prognose rechnen ab sofort
        // mit dem neuen Wert. Die Zielpaces der geplanten Einheiten sind
        // dagegen Zeichenketten, die beim Erstellen des Plans festgeschrieben
        // wurden — sie aenderten sich nie. Profil und Plan liefen damit
        // auseinander, bis zufaellig etwas anderes eine Neuberechnung
        // ausloeste. Bei einer nennenswerten Aenderung wird der Plan jetzt
        // selbst vorgemerkt; RegeneratePlanJob buendelt das ueber seine
        // Sechs-Stunden-Sperre.
        $changed = $previous > 0 ? abs($thresholdPace - $previous) / $previous : 1.0;

        if ($changed >= self::REPLAN_THRESHOLD) {
            $plan = TrainingPlan::where('user_id', $this->userId)->where('is_active', true)->first();

            if ($plan) {
                $plan->update(['needs_plan_update' => true]);
                RegeneratePlanJob::dispatch($this->userId, RegeneratePlanJob::REASON_THRESHOLD)->delay(now()->addMinutes(2));
            }
        }

        // ── Push: die Zahl, die gilt ─────────────────────────────────────
        //
        // Gemeldet von Jan (17.11.2026): Push „neue Schwellenpace 4:21",
        // Heute zeigte 4:11 aus Garmins VO2max. Zonen und Plan rechnen mit
        // der höchsten VDOT-Quelle; die gemessene ist nur eine davon. Die
        // Push nennt deshalb die geltende Schwelle mit Herkunft und kommt
        // nur, wenn sich diese ändert.
        $shown = $profile->thresholdDisplay();

        if ($shown && $shown['pace'] !== $shownBefore && $user->push_notifications_enabled && $user->notify_threshold_pace) {
            $webPush->sendToUser($user, 'Schwellenpace aktualisiert 🏃', self::pushBody($shown, $result['confidence']), '/profile');
        }
    }

    /** @param array{pace: string, source: string, label: string, measured: string|null} $shown */
    public static function pushBody(array $shown, ?string $confidence = null): string
    {
        $body = "Deine Schwellenpace: {$shown['pace']} min/km";

        if ($shown['source'] === 'threshold') {
            $body .= ', aus deinen letzten Läufen berechnet.';
            if ($confidence === 'low') {
                $body .= ' Grobe Schätzung, es fehlt eine harte Einheit als Anker.';
            }
        } else {
            $body .= " ({$shown['label']})."
                . ($shown['measured'] ? " Aus deinen Läufen gemessen: {$shown['measured']}." : '');
        }

        return $body . ' Danach richten sich Zonen und Plan.';
    }

    public function failed(\Throwable $e): void
    {
        RunnerProfile::where('user_id', $this->userId)
            ->update(['threshold_pace_calculating' => false]);
    }
}
