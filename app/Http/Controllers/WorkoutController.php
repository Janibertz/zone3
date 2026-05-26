<?php

namespace App\Http\Controllers;

use App\Models\Workout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class WorkoutController extends Controller
{
    // ── Pages ─────────────────────────────────────────────────────────────────

    public function index()
    {
        $workouts = Workout::where('user_id', Auth::id())
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn ($w) => $this->formatWorkout($w));

        $paceZones = Auth::user()->runnerProfile?->calculatePaceZones() ?? [];

        return Inertia::render('Workouts/Index', [
            'workouts'   => $workouts,
            'paceZones'  => $paceZones,
        ]);
    }

    public function list()
    {
        $workouts = Workout::where('user_id', Auth::id())
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn ($w) => $this->formatWorkout($w));

        return response()->json(['workouts' => $workouts]);
    }

    public function create()
    {
        $paceZones = Auth::user()->runnerProfile?->calculatePaceZones() ?? [];

        return Inertia::render('Workouts/Builder', [
            'workout'   => null,
            'paceZones' => $paceZones,
        ]);
    }

    public function edit(Workout $workout)
    {
        abort_if($workout->user_id !== Auth::id(), 403);

        $paceZones = Auth::user()->runnerProfile?->calculatePaceZones() ?? [];

        return Inertia::render('Workouts/Builder', [
            'workout'   => $this->formatWorkout($workout),
            'paceZones' => $paceZones,
        ]);
    }

    // ── CRUD API ──────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $data = $this->validateWorkout($request);

        $blocks   = $data['blocks'];
        $distM    = Workout::computeDistanceM($blocks);
        $durSec   = Workout::computeDurationSec($blocks);

        $workout = Workout::create([
            'user_id'                => Auth::id(),
            'name'                   => $data['name'],
            'type'                   => $data['type'],
            'description'            => $data['description'] ?? null,
            'blocks'                 => $blocks,
            'tags'                   => $data['tags'] ?? [],
            'estimated_distance_km'  => $distM  > 0 ? round($distM / 1000, 2) : null,
            'estimated_duration_min' => $durSec > 0 ? (int) ceil($durSec / 60)  : null,
        ]);

        return response()->json(['workout' => $this->formatWorkout($workout)]);
    }

    public function update(Request $request, Workout $workout)
    {
        abort_if($workout->user_id !== Auth::id(), 403);

        $data   = $this->validateWorkout($request);
        $blocks = $data['blocks'];
        $distM  = Workout::computeDistanceM($blocks);
        $durSec = Workout::computeDurationSec($blocks);

        $workout->update([
            'name'                   => $data['name'],
            'type'                   => $data['type'],
            'description'            => $data['description'] ?? null,
            'blocks'                 => $blocks,
            'tags'                   => $data['tags'] ?? [],
            'estimated_distance_km'  => $distM  > 0 ? round($distM / 1000, 2) : null,
            'estimated_duration_min' => $durSec > 0 ? (int) ceil($durSec / 60)  : null,
        ]);

        return response()->json(['workout' => $this->formatWorkout($workout->fresh())]);
    }

    public function destroy(Workout $workout)
    {
        abort_if($workout->user_id !== Auth::id(), 403);
        $workout->delete();
        return response()->json(['success' => true]);
    }

    public function duplicate(Workout $workout)
    {
        abort_if($workout->user_id !== Auth::id(), 403);

        $copy = $workout->replicate();
        $copy->name      = $workout->name . ' (Kopie)';
        $copy->times_used = 0;
        $copy->last_used_at = null;
        $copy->save();

        return response()->json(['workout' => $this->formatWorkout($copy)]);
    }

    // ── Garmin ────────────────────────────────────────────────────────────────

    public function sendToGarmin(Request $request, Workout $workout)
    {
        abort_if($workout->user_id !== Auth::id(), 403);

        $user            = Auth::user();
        $hasSavedSession = !empty($user->garmin_session);

        if (! $hasSavedSession) {
            $request->validate([
                'email'    => 'required|email',
                'password' => 'required|string|min:1',
            ]);
        }

        $date = $request->input('date', now()->toDateString());

        $profile    = $user->runnerProfile;
        $threshSec  = $profile ? $this->thresholdPaceToSec($profile->threshold_speed) : null;
        $garminSteps = $this->blocksToGarminSteps($workout->blocks, $threshSec);

        $serviceUrl = config('services.fit.service_url');
        if (! $serviceUrl) {
            return response()->json(['error' => 'FIT-Service nicht verfügbar.'], 503);
        }

        $payload = [
            'name'        => mb_substr($workout->name, 0, 50, 'UTF-8'),
            'description' => $workout->description ?: null,
            'date'        => $date,
            'sport'       => 'running',
            'steps'       => $garminSteps,
        ];

        if ($hasSavedSession) {
            $payload['garmin_session'] = $user->garmin_session;
        } else {
            $payload['garmin_email']    = $request->email;
            $payload['garmin_password'] = $request->password;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(30)
                ->post(rtrim($serviceUrl, '/') . '/send-to-garmin', $payload);

            $json = $response->json() ?: [];

            if (! isset($json['success']) && isset($json['detail'])) {
                $detail = $json['detail'];
                if ($detail === 'session_expired') {
                    $user->update(['garmin_email' => null, 'garmin_session' => null]);
                    return response()->json(['error' => 'session_expired'], 401);
                }
                if ($detail === 'mfa_required')
                    return response()->json(['error' => 'mfa_required'], 422);
                if (str_starts_with($detail, 'login_failed:'))
                    return response()->json(['error' => $detail], 422);
                return response()->json(['error' => $detail], 422);
            }

            if (!empty($json['session'])) {
                $user->update([
                    'garmin_email'   => $request->email,
                    'garmin_session' => $json['session'],
                ]);
            }

            $workout->increment('times_used');
            $workout->update(['last_used_at' => now()]);

            unset($json['session']);
            return response()->json($json);

        } catch (\Exception $e) {
            Log::error('WorkoutController Garmin error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validateWorkout(Request $request): array
    {
        return $request->validate([
            'name'        => 'required|string|max:120',
            'type'        => 'required|in:easy_run,tempo_run,interval,long_run',
            'description' => 'nullable|string|max:2000',
            'blocks'      => 'required|array|min:1',
            'tags'        => 'nullable|array',
            'tags.*'      => 'string|max:40',
        ]);
    }

    private function formatWorkout(Workout $w): array
    {
        return [
            'id'                     => $w->id,
            'name'                   => $w->name,
            'type'                   => $w->type,
            'description'            => $w->description,
            'blocks'                 => $w->blocks,
            'tags'                   => $w->tags ?? [],
            'estimated_distance_km'  => $w->estimated_distance_km,
            'estimated_duration_min' => $w->estimated_duration_min,
            'times_used'             => $w->times_used,
            'last_used_at'           => $w->last_used_at?->format('d.m.Y'),
            'updated_at'             => $w->updated_at->format('d.m.Y'),
        ];
    }

    /**
     * Convert workout blocks to flat Garmin step array.
     * Expands ramp and repeat blocks. Threshold pace (sec/km) used for zone→speed mapping.
     */
    private function blocksToGarminSteps(array $blocks, ?int $threshSec): array
    {
        $steps = [];
        foreach ($blocks as $block) {
            $type = $block['type'];

            if ($type === 'repeat') {
                $reps = max(1, (int) ($block['repetitions'] ?? 1));
                for ($i = 0; $i < $reps; $i++) {
                    foreach ($block['steps'] ?? [] as $sub) {
                        $garminType = ($sub['type'] ?? '') === 'rest' ? 'rest' : 'active';
                        $steps[]    = $this->blockToGarminStep($sub, $garminType, $threshSec);
                    }
                }
                continue;
            }

            if (in_array($type, ['ramp_up', 'ramp_down'])) {
                foreach ($block['steps'] ?? [] as $rampStep) {
                    $zone    = (int) ($rampStep['zone'] ?? 2);
                    $speedMps = $threshSec ? $this->zoneToSpeedMps($zone, $threshSec) : null;
                    $steps[] = [
                        'name'         => "Zone {$zone}",
                        'step_type'    => 'active',
                        'duration_sec' => $rampStep['duration_sec'] ?? null,
                        'meters'       => null,
                        'speedMps'     => $speedMps,
                    ];
                }
                continue;
            }

            $garminType = match ($type) {
                'warmup'   => 'warmup',
                'cooldown' => 'cooldown',
                'rest'     => 'rest',
                default    => 'active',
            };
            $steps[] = $this->blockToGarminStep($block, $garminType, $threshSec);
        }
        return $steps;
    }

    private function blockToGarminStep(array $block, string $garminType, ?int $threshSec): array
    {
        $mode        = $block['duration_mode'] ?? 'time';
        $durationSec = $mode === 'time' ? ($block['duration_sec'] ?? null) : null;
        $meters      = $mode === 'distance' ? ($block['distance_m'] ?? null) : null;

        $speedMps = null;
        if (!empty($block['pace'])) {
            $speedMps = $this->paceToSpeedMps($block['pace']);
        } elseif (!empty($block['pace_zone']) && $threshSec) {
            $speedMps = $this->zoneToSpeedMps((int) $block['pace_zone'], $threshSec);
        }

        return [
            'name'         => $block['label'] ?? ucfirst($garminType),
            'step_type'    => $garminType,
            'duration_sec' => $durationSec ? (int) $durationSec : null,
            'meters'       => $meters ? (int) $meters : null,
            'speedMps'     => $speedMps,
            'lap_button'   => !empty($block['lap_button']),
        ];
    }

    private function paceToSpeedMps(string $pace): ?float
    {
        // Handle range "5:30-6:00" → take faster bound
        $pace  = trim(explode('-', $pace)[0]);
        $parts = explode(':', $pace);
        if (count($parts) !== 2) return null;
        $secPerKm = (int) $parts[0] * 60 + (int) $parts[1];
        return $secPerKm > 0 ? round(1000 / $secPerKm, 5) : null;
    }

    /**
     * Zone offsets from threshold pace (sec/km):
     * Z1: +105s, Z2: +60s, Z3: +30s, Z4: +10s, Z5: -10s
     */
    private function zoneToSpeedMps(int $zone, int $threshSec): float
    {
        $offsets = [1 => 105, 2 => 60, 3 => 30, 4 => 10, 5 => -10];
        $secPerKm = max(60, $threshSec + ($offsets[$zone] ?? 30));
        return round(1000 / $secPerKm, 5);
    }

    private function thresholdPaceToSec(?float $thresholdSpeed): ?int
    {
        if (! $thresholdSpeed) return null;
        $mins = (int) $thresholdSpeed;
        $secs = (int) (($thresholdSpeed - $mins) * 60);
        return $mins * 60 + $secs;
    }
}
