<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Startkatalog aus `database/data/strength/` (05.11.2026).
 *
 * Läuft genau einmal. Danach gilt die Datenbank, gepflegt im Admin unter
 * /admin/strength-exercises — eine Änderung an der Datei wirkt nicht mehr.
 */
return new class extends Migration
{
    public function up(): void
    {
        $dir = database_path('data/strength');
        $now = now();

        foreach (require $dir.'/exercises.php' as $i => $e) {
            if (DB::table('strength_exercises')->where('key', $e['key'])->exists()) {
                continue;
            }

            $id = DB::table('strength_exercises')->insertGetId([
                'key' => $e['key'],
                'name' => $e['name'],
                'instructions' => $e['instructions'],
                'group' => $e['group'],
                'equipment' => json_encode($e['equipment']),
                'level' => $e['level'],
                'default_sets' => $e['sets'],
                'default_reps' => $e['reps'],
                'default_load' => null,
                'wger_id' => $e['wger_id'],
                'license' => $e['license'],
                'license_author' => $e['author'],
                'active' => true,
                'sort' => ($i + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $file = $dir.'/'.$e['key'].'.jpg';
            if ($e['wger_id'] && is_file($file)) {
                DB::table('strength_exercise_images')->insert([
                    'strength_exercise_id' => $id,
                    'mime' => 'image/jpeg',
                    'data' => base64_encode(file_get_contents($file)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('strength_exercise_images')->delete();
        DB::table('strength_exercises')->delete();
    }
};
