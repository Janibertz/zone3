<?php

namespace App\Services\Coach;

use App\Services\PaceFormat;

/**
 * Wie der Coach eine Einheit schreibt — dieselben Regeln für die alten
 * Werkzeuge und für `plan_change` (Stufe 2, 22.11.2026). Herausgelöst aus
 * CoachChatService, damit beide Wege nicht auseinanderlaufen.
 */
class SessionWriter
{
    public function sessionTitleFor(string $type): string
    {
        return [
            'easy_run'        => 'Lockerer Lauf',
            'tempo_run'       => 'Tempolauf',
            'interval'        => 'Intervalltraining',
            'long_run'        => 'Langer Lauf',
            'progressive_run' => 'Progressiver Lauf',
            'test_run'        => 'Testlauf',
            'race_prep'       => 'Rennvorbereitung',
            'rest'            => 'Ruhetag',
        ][$type] ?? 'Training';
    }

    public function intensityFor(string $type): string
    {
        return match ($type) {
            'rest'                            => 'rest',
            'easy_run'                        => 'low',
            'interval', 'test_run', 'race_prep' => 'high',
            default                           => 'medium',
        };
    }

    public function isRun(string $type): bool
    {
        return in_array($type, \App\Services\TrainingPlanValidator::RUN_TYPES, true);
    }

    public function newSessionFor(\App\Models\User $user, string $date, string $type): \App\Models\TrainingSession
    {
        $plan = \App\Models\TrainingPlan::where('user_id', $user->id)
            ->where('is_active', true)
            ->latest()
            ->first();

        $session = new \App\Models\TrainingSession();
        $session->user_id          = $user->id;
        $session->training_plan_id = $plan?->id;
        $session->event_id         = $plan?->event_id;
        $session->planned_date     = $date;
        $session->status           = 'planned';
        $session->sort_order       = $this->isRun($type) ? 1 : 2;
        $session->type             = $type;
        $session->title            = $this->sessionTitleFor($type);
        $session->description      = '';
        $session->intensity        = $this->intensityFor($type);

        return $session;
    }

    public function applySessionFields(\App\Models\TrainingSession $session, array $args, ?\App\Models\User $user = null): void
    {
        $given = [];

        foreach (['type', 'title', 'description', 'distance_km', 'duration_min', 'pace_target', 'zone'] as $field) {
            if (array_key_exists($field, $args) && $args[$field] !== null && $args[$field] !== '') {
                $session->{$field} = $args[$field];
                $given[$field]     = true;
            }
        }

        if ($this->isRun($session->type)) {
            // Aus einer Radeinheit wird wieder ein Lauf — die Sportart
            // gehört zur alten Einheit (NULL heisst Laufen).
            $session->sport_type = null;
            $this->reconcileDistanceAndDuration($session, $given, $user);
        }

        $session->title       = $session->title       ?: $this->sessionTitleFor($session->type);
        $session->description = $session->description ?? '';
        $session->intensity   = $session->intensity   ?: $this->intensityFor($session->type);

        // Was ueber den Chat gesetzt wurde, hat der Athlet ausdruecklich
        // bestellt. Beide Plan-Jobs raeumen vor dem Schreiben alles Geplante
        // ab — ohne diese Markierung war ein "ich moechte am Sonntag 25 km
        // laufen" beim naechsten Durchlauf still wieder weg.
        $session->pinned_at = now();

        // Der Ablauf kommt aus dem Katalog — derselbe wie im Plan, damit
        // der Coach keine Struktur erfindet, die sonst nirgends gilt.
        // Offenes Ein-/Auslaufen bleibt, solange der Athlet nichts anderes sagt.
        $open = \App\Services\Run\SessionStructure::openEnds($session->steps);
        $session->steps = null;
        app(\App\Services\Run\SessionStructure::class)->apply(
            $session,
            $args['workout'] ?? null,
            $args['easy_pace'] ?? null,
            $args['hard_pace'] ?? null,
            isset($args['open_warmup']) ? (bool) $args['open_warmup'] : $open['warmup'],
            isset($args['open_cooldown']) ? (bool) $args['open_cooldown'] : $open['cooldown'],
        );

        // Kraft: Übungen nur aus dem Katalog (Admin → Kraftübungen).
        app(\App\Services\Strength\StrengthCatalog::class)->applyTo($session, $args['exercises'] ?? null);
    }

    public function stepsLine(\App\Models\TrainingSession $session): string
    {
        if (empty($session->steps) || ! is_array($session->steps)) {
            return '';
        }

        $parts = array_map(function (array $s): string {
            $label = $s['label'] ?? ($s['type'] ?? 'Abschnitt');
            if (! empty($s['open'])) {
                return "{$label} (offen, Ende per Lap-Taste)";
            }
            $amount = ! empty($s['distance_m'])
                ? ($s['distance_m'] >= 1000 ? round($s['distance_m'] / 1000, 1) . ' km' : $s['distance_m'] . ' m')
                : (! empty($s['duration_min']) ? round((float) $s['duration_min'], 1) . ' min' : '');
            $reps = ! empty($s['repetitions']) ? $s['repetitions'] . '× ' : '';

            return trim("{$reps}{$label} {$amount}");
        }, $session->steps);

        return ' Eingetragener Ablauf: ' . implode(' · ', $parts) . '. Bestätige dem Athleten nur diese Abschnitte — was hier fehlt, gibt es nicht.';
    }

    public static function exercisesParam(): array
    {
        return [
            'type'        => 'array',
            'description' => 'Nur bei strength/core/mobility: Übungen aus dem Kraft-Katalog. Sätze und Wiederholungen legst du fest. Weglassen = das System wählt ein Programm.',
            'items'       => ['type' => 'object', 'properties' => [
                'key'  => ['type' => 'string', 'description' => 'Schlüssel aus dem Kraft-Katalog: ' . app(\App\Services\Strength\StrengthCatalog::class)->all()->where('active', true)->pluck('key')->implode(', ')],
                'sets' => ['type' => 'integer'],
                'reps' => ['type' => 'string', 'description' => 'z. B. "10", "8 je Bein", "30 s"'],
                'load' => ['type' => 'string', 'description' => 'z. B. "16 kg", "Körpergewicht"'],
                'note' => ['type' => 'string'],
            ], 'required' => ['key']],
        ];
    }

    public static function workoutEnum(): array
    {
        return array_keys(\App\Services\Run\RunWorkouts::DESCRIPTIONS);
    }

    public static function workoutHint(): string
    {
        return collect(\App\Services\Run\RunWorkouts::VARIANTS)
            ->map(fn ($v, $type) => $type . ': ' . implode(', ', $v))
            ->implode('; ');
    }

    public function reconcileDistanceAndDuration(
        \App\Models\TrainingSession $session,
        array $given,
        ?\App\Models\User $user,
    ): void {
        $paceSec = $this->paceSecondsFor($session->type, $user);

        $hasDistance = isset($given['distance_km']);
        $hasDuration = isset($given['duration_min']);

        if ($hasDistance && ! $hasDuration) {
            $session->duration_min = (int) round($session->distance_km * $paceSec / 60);
        } elseif ($hasDuration && ! $hasDistance) {
            $session->distance_km = round($session->duration_min * 60 / $paceSec, 1);
        } elseif (! $hasDistance && ! $hasDuration && isset($given['type']) && $session->duration_min > 0) {
            // Nur der Typ hat gewechselt: die Dauer bleibt das Verlässliche,
            // die Distanz folgt der neuen Pace.
            $session->distance_km = round($session->duration_min * 60 / $paceSec, 1);
        }

        // Eine Pace aus der alten Einheit passt nach einem Typwechsel nicht
        // mehr. Sie wird dann neu gesetzt statt irrezuführen.
        if (! isset($given['pace_target']) && isset($given['type'])) {
            $session->pace_target = PaceFormat::fromSeconds($paceSec);
        }
    }

    public function paceSecondsFor(string $type, ?\App\Models\User $user): int
    {
        $threshold = $user?->runnerProfile?->threshold_speed;
        $t         = $threshold > 0 ? $threshold * 60 : 330;

        return (int) round(match ($type) {
            'long_run'         => $t + 70,
            'easy_run'         => $t + 60,
            'progressive_run'  => $t + 45,
            'interval'         => $t + 25,
            'tempo_run'        => $t + 15,
            'test_run'         => $t - 10,
            default            => $t + 40,
        });
    }

    public function dayLabel(string $date): string
    {
        $days = [1 => 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
        $d    = \Carbon\CarbonImmutable::parse($date);

        return $days[$d->isoWeekday()] . ', ' . $d->format('d.m.');
    }

    public function doubleAllowedOn(\App\Models\User $user, string $date): bool
    {
        $profile = $user->runnerProfile;
        if (! $profile || $profile->trainingModelOn($date) !== 'norwegian') {
            return false;
        }

        $key    = strtolower(\Carbon\Carbon::parse($date)->englishDayOfWeek);
        $second = (int) ($profile->weekly_availability[$key]['second_min'] ?? 0);

        return $second >= \App\Services\WeeklyPatternService::MIN_SECOND_WINDOW_MINUTES;
    }

    public function fillBikeSession(\App\Models\TrainingSession $session, string $kind, int $minutes, ?int $ftp): array
    {
        $workout = app(\App\Services\Bike\BikeWorkouts::class)->build($kind, $minutes, $ftp);

        $session->fill([
            'type'           => 'cross_training',
            'sport_type'     => 'VirtualRide',
            'title'          => $workout['title'],
            'description'    => $workout['description'],
            'intensity'      => $workout['intensity'],
            'duration_min'   => $workout['duration_min'],
            'distance_km'    => null,
            'pace_target'    => null,
            'zone'           => null,
            'steps'          => $workout['steps'],
            'nutrition_tips' => null,
            // Der Athlet hat es bestellt — eine Neuberechnung bleibt davon weg.
            'pinned_at'      => now(),
        ])->save();

        return $workout;
    }

    public function wattsNote(array $workout): string
    {
        $main = collect($workout['steps'])->firstWhere('type', 'work');

        return ! empty($main['watts']) ? " ({$main['watts']})" : ' (ohne FTP im Profil nur in % FTP)';
    }

    public function invalidateCoachCaches(\App\Models\User $user): void
    {
        $profile = $user->runnerProfile ?? \App\Models\RunnerProfile::firstOrCreate(['user_id' => $user->id]);
        $profile->update([
            'today_recommendation' => null,
            'recommendation_date'  => null,
            'daily_message'        => null,
            'daily_message_date'   => null,
        ]);
    }
}
