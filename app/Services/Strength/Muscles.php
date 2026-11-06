<?php

namespace App\Services\Strength;

/**
 * Welche Muskeln eine Übung belastet — für die Körpergrafik.
 *
 * Weder Garmin noch COROS schreiben Muskeln in die FIT-Datei, nur die Übung.
 * Strava hat dafür eine eigene Tabelle, Zone3 auch: je Katalog-Übung in
 * `strength_exercises.muscles` (Admin), und für Übungen außerhalb des
 * Katalogs grob nach Garmins Kategorie (`CATEGORY`). Eine unbekannte Übung
 * färbt nichts — lieber eine leere Stelle als eine erfundene.
 *
 * Die Schlüssel sind die Flächen in `Components/Strength/MuscleMap.vue`.
 */
class Muscles
{
    public const LABELS = [
        'chest'       => 'Brust',
        'shoulders'   => 'Schultern',
        'biceps'      => 'Bizeps',
        'triceps'     => 'Trizeps',
        'abs'         => 'Bauch',
        'obliques'    => 'Seitlicher Bauch',
        'hip_flexors' => 'Hüftbeuger',
        'quads'       => 'Oberschenkel vorn',
        'adductors'   => 'Adduktoren',
        'shins'       => 'Schienbein',
        'calves'      => 'Waden',
        'glutes'      => 'Gesäß',
        'hamstrings'  => 'Oberschenkel hinten',
        'lats'        => 'Breiter Rücken',
        'traps'       => 'Nacken',
        'lower_back'  => 'Unterer Rücken',
    ];

    /**
     * Grob nach Garmins Kategorie, wenn die Übung nicht im Katalog steht.
     * Kategorien ohne klaren Schwerpunkt (Cardio, Ganzkörper, Aufwärmen,
     * Laufen) fehlen absichtlich.
     *
     * @var array<string, array{0: list<string>, 1: list<string>}>
     */
    public const CATEGORY = [
        'BENCH_PRESS'        => [['chest', 'triceps'], ['shoulders']],
        'CALF_RAISE'         => [['calves'], []],
        'CARRY'              => [['traps'], ['abs', 'obliques']],
        'CHOP'               => [['obliques'], ['abs', 'shoulders']],
        'CORE'               => [['abs'], ['obliques', 'lower_back']],
        'CRUNCH'             => [['abs'], ['obliques']],
        'CURL'               => [['biceps'], []],
        'DEADLIFT'           => [['hamstrings', 'glutes'], ['lower_back']],
        'FLYE'               => [['chest'], ['shoulders']],
        'HIP_RAISE'          => [['glutes'], ['hamstrings']],
        'HIP_STABILITY'      => [['glutes'], ['abs']],
        'HIP_SWING'          => [['glutes', 'hamstrings'], ['lower_back', 'shoulders']],
        'HYPEREXTENSION'     => [['lower_back'], ['glutes', 'hamstrings']],
        'LATERAL_RAISE'      => [['shoulders'], []],
        'LEG_CURL'           => [['hamstrings'], ['calves']],
        'LEG_RAISE'          => [['abs', 'hip_flexors'], []],
        'LUNGE'              => [['quads', 'glutes'], ['hamstrings', 'adductors']],
        'OLYMPIC_LIFT'       => [['quads', 'glutes', 'hamstrings'], ['traps', 'shoulders']],
        'PLANK'              => [['abs'], ['shoulders', 'obliques']],
        'PLYO'               => [['quads', 'glutes'], ['calves']],
        'PULL_UP'            => [['lats'], ['biceps', 'traps']],
        'PUSH_UP'            => [['chest', 'triceps'], ['shoulders', 'abs']],
        'ROW'                => [['lats'], ['biceps', 'traps']],
        'SHOULDER_PRESS'     => [['shoulders'], ['triceps']],
        'SHOULDER_STABILITY' => [['shoulders'], ['traps']],
        'SHRUG'              => [['traps'], []],
        'SIT_UP'             => [['abs'], ['hip_flexors']],
        'SQUAT'              => [['quads', 'glutes'], ['hamstrings']],
        'TRICEPS_EXTENSION'  => [['triceps'], []],
        'BANDED_EXERCISES'   => [['glutes'], []],
        'SANDBAG'            => [['glutes', 'quads'], ['shoulders']],
        'SLED'               => [['quads', 'glutes'], ['calves']],
        'SUSPENSION'         => [['lats', 'chest'], ['abs']],
    ];

    /** Startwerte für den Katalog (Migration), danach pflegt sie der Admin. */
    public const CATALOG = [
        'squat'                   => [['quads', 'glutes'], ['hamstrings', 'lower_back']],
        'goblet_squat'            => [['quads', 'glutes'], ['abs', 'hamstrings']],
        'bulgarian_split_squat'   => [['quads', 'glutes'], ['hamstrings', 'adductors']],
        'reverse_lunge'           => [['quads', 'glutes'], ['hamstrings', 'adductors']],
        'walking_lunge'           => [['quads', 'glutes'], ['hamstrings', 'adductors']],
        'lateral_lunge'           => [['adductors', 'glutes', 'quads'], ['hamstrings']],
        'step_up'                 => [['quads', 'glutes'], ['calves', 'hamstrings']],
        'pistol_squat'            => [['quads', 'glutes'], ['abs', 'calves']],
        'back_squat'              => [['quads', 'glutes'], ['hamstrings', 'lower_back']],
        'leg_press'               => [['quads', 'glutes'], ['hamstrings']],
        'wall_sit'                => [['quads'], ['glutes']],
        'glute_bridge'            => [['glutes'], ['hamstrings', 'lower_back']],
        'single_leg_glute_bridge' => [['glutes'], ['hamstrings', 'abs']],
        'hip_thrust'              => [['glutes'], ['hamstrings', 'quads']],
        'romanian_deadlift'       => [['hamstrings', 'glutes'], ['lower_back']],
        'single_leg_deadlift'     => [['hamstrings', 'glutes'], ['lower_back', 'abs']],
        'kettlebell_swing'        => [['glutes', 'hamstrings'], ['lower_back', 'shoulders', 'abs']],
        'kettlebell_deadlift'     => [['hamstrings', 'glutes'], ['lower_back', 'quads']],
        'deadlift'                => [['hamstrings', 'glutes', 'lower_back'], ['quads', 'traps', 'lats']],
        'clamshell'               => [['glutes'], []],
        'lateral_band_walk'       => [['glutes'], ['quads']],
        'nordic_curl'             => [['hamstrings'], ['glutes', 'calves']],
        'good_morning'            => [['hamstrings', 'lower_back'], ['glutes']],
        'calf_raise'              => [['calves'], []],
        'single_leg_calf_raise'   => [['calves'], []],
        'seated_calf_raise'       => [['calves'], []],
        'tibialis_raise'          => [['shins'], []],
        'plank'                   => [['abs'], ['shoulders', 'obliques']],
        'side_plank'              => [['obliques'], ['abs', 'shoulders', 'glutes']],
        'bird_dog'                => [['lower_back', 'glutes'], ['abs', 'shoulders']],
        'dead_bug'                => [['abs'], ['hip_flexors', 'obliques']],
        'hollow_hold'             => [['abs'], ['hip_flexors']],
        'pallof_press'            => [['obliques', 'abs'], ['shoulders']],
        'russian_twist'           => [['obliques'], ['abs', 'hip_flexors']],
        'back_extension'          => [['lower_back'], ['glutes', 'hamstrings']],
        'copenhagen_plank'        => [['adductors', 'obliques'], ['abs']],
        'push_up'                 => [['chest', 'triceps'], ['shoulders', 'abs']],
        'dumbbell_row'            => [['lats'], ['biceps', 'traps', 'shoulders']],
        'band_face_pull'          => [['shoulders', 'traps'], ['biceps']],
        'pull_up'                 => [['lats'], ['biceps', 'traps']],
        'high_knees'              => [['hip_flexors', 'quads'], ['calves']],
        'single_leg_landing'      => [['quads', 'glutes'], ['calves']],
        'skater_jump'             => [['glutes', 'quads'], ['adductors', 'calves']],
        'hip_flexor_stretch'      => [['hip_flexors'], ['quads']],
        'pigeon_stretch'          => [['glutes'], ['hip_flexors']],
        'ankle_rocks'             => [['calves'], ['shins']],
        'hip_circles'             => [['hip_flexors', 'glutes'], []],
        'hamstring_stretch_band'  => [['hamstrings'], ['calves']],
        'calf_stretch'            => [['calves'], []],
    ];

    /**
     * Die Muskeln einer Übung als ['primary' => [...], 'secondary' => [...]],
     * aus dem Katalog oder sonst aus Garmins Kategorie.
     */
    public static function forExercise(?array $catalogMuscles, ?string $garminCategory): array
    {
        if (is_array($catalogMuscles) && ($catalogMuscles['primary'] ?? []) !== []) {
            return self::clean($catalogMuscles['primary'] ?? [], $catalogMuscles['secondary'] ?? []);
        }

        [$primary, $secondary] = self::CATEGORY[$garminCategory ?? ''] ?? [[], []];

        return self::clean($primary, $secondary);
    }

    /**
     * Wie stark jede Fläche gefärbt wird: Sätze als Hauptmuskel zählen voll,
     * als Nebenmuskel halb; die stärkste Fläche ist 1.
     *
     * @param list<array{muscles: array, sets: int}> $exercises
     * @return array<string, float>
     */
    public static function load(array $exercises): array
    {
        $load = [];
        foreach ($exercises as $ex) {
            $sets = max(1, (int) ($ex['sets'] ?? 1));
            foreach ($ex['muscles']['primary'] ?? [] as $m) {
                $load[$m] = ($load[$m] ?? 0) + $sets;
            }
            foreach ($ex['muscles']['secondary'] ?? [] as $m) {
                $load[$m] = ($load[$m] ?? 0) + $sets / 2;
            }
        }

        $max = $load === [] ? 0 : max($load);
        if ($max <= 0) {
            return [];
        }

        arsort($load);

        return array_map(fn ($v) => round($v / $max, 2), $load);
    }

    /** @return array{primary: list<string>, secondary: list<string>} */
    public static function clean(array $primary, array $secondary): array
    {
        $primary   = array_values(array_unique(array_intersect($primary, array_keys(self::LABELS))));
        $secondary = array_values(array_diff(array_unique(array_intersect($secondary, array_keys(self::LABELS))), $primary));

        return ['primary' => $primary, 'secondary' => $secondary];
    }
}
