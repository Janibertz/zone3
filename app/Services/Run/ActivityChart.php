<?php

namespace App\Services\Run;

use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\TrainingSession;

/**
 * Die Verlaufskurve der Aktivitätsseite (Mockup „Zone3 Einheiten mit Profil
 * und Soll/Ist“, Etappe 4): Pace, Puls und Höhe über die Zeit, dazu das
 * Zielband aus dem Ablauf und gestrichelt dieselbe Einheit beim letzten Mal.
 *
 * Gespeichert sind Punkte alle 2 s; an die Seite gehen höchstens MAX_POINTS,
 * die Pace über 20 s geglättet — eine Kurve aus 2-s-Tempo zittert, weil GPS
 * zittert.
 */
class ActivityChart
{
    public const MAX_POINTS = 480;

    /** Glättung der Pace in Sekunden. */
    private const PACE_SMOOTH_S = 20;

    /** Schneller als 2:00/km oder langsamer als 15:00/km ist Stehen oder GPS. */
    private const PACE_MIN = 120;
    private const PACE_MAX = 900;

    private const RUN_TYPES = ['Run', 'TrailRun', 'VirtualRun'];

    /**
     * @return array{t: list<int>, pace?: list<?int>, hr?: list<?int>, alt?: list<?float>, duration: int}|null
     */
    public function series(?ActivityStream $stream, bool $run = true): ?array
    {
        $data = $stream?->data;
        if (! $data || empty($data['t'])) {
            return null;
        }

        $t    = $data['t'];
        $n    = count($t);
        $step = max(1, (int) ceil($n / self::MAX_POINTS));
        $win  = max(1, (int) round(self::PACE_SMOOTH_S / max(1, $stream->interval_s)));

        $out = ['t' => [], 'duration' => (int) end($t)];
        $v   = $data['v'] ?? null;
        $hr  = $data['hr'] ?? null;
        $alt = $data['alt'] ?? null;

        for ($i = 0; $i < $n; $i += $step) {
            $out['t'][] = (int) $t[$i];

            if ($run && $v) {
                $slice = array_filter(array_slice($v, max(0, $i - $win + 1), $win), fn ($x) => $x !== null && $x > 0);
                $speed = $slice ? array_sum($slice) / count($slice) : null;
                $pace  = $speed ? 1000 / $speed : null;
                $out['pace'][] = $pace !== null && $pace >= self::PACE_MIN && $pace <= self::PACE_MAX ? (int) round($pace) : null;
            }
            if ($hr) {
                $slice = array_filter(array_slice($hr, $i, $step), fn ($x) => $x);
                $out['hr'][] = $slice ? (int) round(array_sum($slice) / count($slice)) : null;
            }
            if ($alt) {
                $out['alt'][] = $alt[$i] ?? null;
            }
        }

        foreach (['pace', 'hr', 'alt'] as $k) {
            if (isset($out[$k]) && ! array_filter($out[$k], fn ($x) => $x !== null)) {
                unset($out[$k]);
            }
        }

        return count($out) > 2 ? $out : null;
    }

    /**
     * Dieselbe Einheit beim letzten Mal (Jan, 25.11.2026): die letzte
     * erledigte Einheit mit demselben Baustein, vor dieser, mit Messpunkten.
     */
    public function comparison(?TrainingSession $session, Activity $activity): ?array
    {
        if (! $session?->workout_variant || ! in_array($activity->type, self::RUN_TYPES, true)) {
            return null;
        }

        $previous = TrainingSession::query()
            ->where('user_id', $session->user_id)
            ->where('workout_variant', $session->workout_variant)
            ->where('status', 'completed')
            ->where('id', '!=', $session->id)
            ->whereNotNull('activity_id')
            ->where('activity_id', '!=', $activity->id)
            ->whereDate('planned_date', '<', $session->planned_date)
            ->whereHas('activity.stream')
            ->orderByDesc('planned_date')
            ->with('activity.stream')
            ->first();

        $series = $previous ? $this->series($previous->activity->stream) : null;
        if (! $series || empty($series['pace'])) {
            return null;
        }

        return [
            'activity_id' => $previous->activity_id,
            'date'        => $previous->planned_date->locale('de')->isoFormat('dd DD.MM.'),
            'title'       => $previous->title,
        ] + $series;
    }
}
