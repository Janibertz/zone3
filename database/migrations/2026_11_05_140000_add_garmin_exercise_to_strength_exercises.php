<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kraft auf der Garmin-Uhr (05.11.2026).
 *
 * Garmin kennt Übungen nur aus seiner festen Liste (FIT-Profil:
 * `exercise_category` und je Kategorie `*_exercise_name`). Jede Übung des
 * Katalogs bekommt dafür Kategorie und Name; die Uhr zeigt dann ihre eigene
 * Animation und zählt Wiederholungen. Wo die Liste nichts Passendes hat
 * (einbeinige Landung), bleibt beides leer — die Uhr zeigt dann nur den
 * Namen aus der Beschreibung.
 *
 * Die Werte stammen aus `@garmin/fitsdk` (Profile.types), umgeschrieben in
 * die GROSS_SCHREIBWEISE der Training API. Der Admin kann sie ändern.
 */
return new class extends Migration
{
    private const MAP = [
        'squat'                   => ['SQUAT', 'SQUAT'],
        'goblet_squat'            => ['SQUAT', 'GOBLET_SQUAT'],
        'bulgarian_split_squat'   => ['LUNGE', 'DUMBBELL_BULGARIAN_SPLIT_SQUAT'],
        'reverse_lunge'           => ['LUNGE', 'LUNGE'],
        'walking_lunge'           => ['LUNGE', 'WALKING_LUNGE'],
        'lateral_lunge'           => ['LUNGE', 'SIDE_LUNGE'],
        'step_up'                 => ['SQUAT', 'STEP_UP'],
        'pistol_squat'            => ['SQUAT', 'PISTOL_SQUAT'],
        'back_squat'              => ['SQUAT', 'BARBELL_BACK_SQUAT'],
        'leg_press'               => ['SQUAT', 'LEG_PRESS'],
        'wall_sit'                => ['SQUAT', 'BODY_WEIGHT_WALL_SQUAT'],
        'glute_bridge'            => ['HIP_RAISE', 'HIP_RAISE'],
        'single_leg_glute_bridge' => ['HIP_RAISE', 'SINGLE_LEG_HIP_RAISE'],
        'hip_thrust'              => ['HIP_RAISE', 'BARBELL_HIP_THRUST_WITH_BENCH'],
        'romanian_deadlift'       => ['DEADLIFT', 'ROMANIAN_DEADLIFT'],
        'single_leg_deadlift'     => ['DEADLIFT', 'SINGLE_LEG_ROMANIAN_DEADLIFT_WITH_DUMBBELL'],
        'kettlebell_swing'        => ['HIP_RAISE', 'KETTLEBELL_SWING'],
        'kettlebell_deadlift'     => ['DEADLIFT', 'KETTLEBELL_DEADLIFT'],
        'deadlift'                => ['DEADLIFT', 'BARBELL_DEADLIFT'],
        'clamshell'               => ['HIP_RAISE', 'CLAMS'],
        'lateral_band_walk'       => ['HIP_STABILITY', 'LATERAL_WALKS_WITH_BAND_AT_ANKLES'],
        'nordic_curl'             => ['LEG_CURL', 'LEG_CURL'],
        'good_morning'            => ['LEG_CURL', 'GOOD_MORNING'],
        'calf_raise'              => ['CALF_RAISE', 'STANDING_CALF_RAISE'],
        'single_leg_calf_raise'   => ['CALF_RAISE', 'SINGLE_LEG_STANDING_CALF_RAISE'],
        'seated_calf_raise'       => ['CALF_RAISE', 'SEATED_CALF_RAISE'],
        'tibialis_raise'          => ['CALF_RAISE', 'SEATED_DUMBBELL_TOE_RAISE'],
        'plank'                   => ['PLANK', 'PLANK'],
        'side_plank'              => ['PLANK', 'SIDE_PLANK'],
        'bird_dog'                => ['CORE', 'ARM_AND_LEG_EXTENSION_ON_KNEES'],
        'dead_bug'                => ['HIP_STABILITY', 'DEAD_BUG'],
        'hollow_hold'             => ['CRUNCH', 'HOLLOW_ROCK'],
        'pallof_press'            => ['CORE', 'CABLE_CORE_PRESS'],
        'russian_twist'           => ['CORE', 'RUSSIAN_TWIST'],
        'back_extension'          => ['HYPEREXTENSION', 'SUPERMAN_FROM_FLOOR'],
        'copenhagen_plank'        => ['PLANK', 'SIDE_PLANK_WITH_FEET_ON_BENCH'],
        'push_up'                 => ['PUSH_UP', 'PUSH_UP'],
        'dumbbell_row'            => ['ROW', 'DUMBBELL_ROW'],
        'band_face_pull'          => ['ROW', 'BANDED_FACE_PULLS'],
        'pull_up'                 => ['PULL_UP', 'PULL_UP'],
        'high_knees'              => ['WARM_UP', 'WALKING_HIGH_KNEES'],
        'single_leg_landing'      => [null, null],
        'skater_jump'             => ['PLYO', 'LATERAL_LEAP_AND_HOP'],
        'hip_flexor_stretch'      => ['WARM_UP', 'STRETCH_LUNGING_HIP_FLEXOR'],
        'pigeon_stretch'          => ['WARM_UP', 'STRETCH_PIGEON_POSE'],
        'ankle_rocks'             => ['WARM_UP', 'ANKLE_LUNGES'],
        'hip_circles'             => ['WARM_UP', 'HIP_CIRCLES'],
        'hamstring_stretch_band'  => ['WARM_UP', 'STRETCH_HAMSTRING'],
        'calf_stretch'            => ['WARM_UP', 'STRETCH_CALF'],
    ];

    public function up(): void
    {
        Schema::table('strength_exercises', function (Blueprint $table) {
            $table->string('garmin_category', 40)->nullable()->after('license_author');
            $table->string('garmin_name', 80)->nullable()->after('garmin_category');
        });

        foreach (array_filter(self::MAP, fn ($v) => $v[0] !== null) as $key => [$category, $name]) {
            DB::table('strength_exercises')->where('key', $key)
                ->update(['garmin_category' => $category, 'garmin_name' => $name]);
        }
    }

    public function down(): void
    {
        Schema::table('strength_exercises', function (Blueprint $table) {
            $table->dropColumn(['garmin_category', 'garmin_name']);
        });
    }
};
