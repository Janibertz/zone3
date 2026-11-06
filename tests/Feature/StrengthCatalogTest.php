<?php

namespace Tests\Feature;

use App\Models\StrengthExercise;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Strength\StrengthCatalog;
use App\Services\TrainingPlanValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Kraft-Katalog (05.11.2026): das Modell wählt Übungen aus dem Katalog,
 * statt Namen zu erfinden; Bild, Ausführung und Quelle kommen dazu.
 */
class StrengthCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function catalog(): StrengthCatalog
    {
        $c = app(StrengthCatalog::class);
        $c->forget();

        return $c;
    }

    public function test_the_start_catalog_is_seeded_with_images(): void
    {
        $this->assertSame(49, StrengthExercise::count());
        $this->assertSame(37, \App\Models\StrengthExerciseImage::count());

        $squat = StrengthExercise::where('key', 'goblet_squat')->first();
        $this->assertSame('Bild: philip, CC-BY-SA 4.0, wger.de', $squat->credit());
    }

    public function test_available_respects_equipment_and_experience(): void
    {
        $keys = $this->catalog()->available([], 'beginner')->pluck('key');

        $this->assertContains('squat', $keys);
        $this->assertNotContains('kettlebell_swing', $keys, 'ohne Kettlebell keine Swings');
        $this->assertNotContains('pistol_squat', $keys, 'Einsteiger bekommen keine einbeinige Kniebeuge');

        // Studio bringt Hanteln, Kettlebell und Band mit.
        $gym = $this->catalog()->available(['gym'], 'advanced')->pluck('key');
        $this->assertContains('kettlebell_swing', $gym);
        $this->assertContains('lateral_band_walk', $gym);
        $this->assertContains('deadlift', $gym);
    }

    public function test_inactive_exercises_are_not_offered(): void
    {
        StrengthExercise::where('key', 'squat')->update(['active' => false]);

        $this->assertNotContains('squat', $this->catalog()->available()->pluck('key'));
        $this->assertDoesNotMatchRegularExpression('/(^|[ ,:])squat \(/', $this->catalog()->promptList());
        $this->assertMatchesRegularExpression('/(^|[ ,:])wall_sit \(/', $this->catalog()->promptList());
    }

    public function test_normalize_keeps_catalog_picks_and_the_models_numbers(): void
    {
        $result = $this->catalog()->normalize([
            ['key' => 'goblet_squat', 'sets' => 4, 'reps' => '6', 'load' => '24 kg'],
            ['name' => 'Bird Dog', 'sets' => 2, 'reps' => '12 je Seite'],      // über den Namen
            ['name' => 'Turkish Get-up', 'sets' => 3, 'reps' => '3'],          // nicht im Katalog
            ['key' => 'goblet_squat', 'sets' => 1, 'reps' => '1'],             // doppelt
            ['key' => 'plank', 'sets' => 99, 'reps' => ''],                    // Unsinn → Vorgabe
        ], 'strength', '2026-11-10', ['equipment' => ['kettlebell'], 'experience' => 'intermediate']);

        $this->assertFalse($result['defaulted']);
        $this->assertSame(['Turkish Get-up'], $result['dropped']);
        $this->assertSame(['goblet_squat', 'bird_dog', 'plank'], array_column($result['exercises'], 'key'));

        $this->assertSame(4, $result['exercises'][0]['sets']);
        $this->assertSame('6', $result['exercises'][0]['reps']);
        $this->assertSame('24 kg', $result['exercises'][0]['load']);
        $this->assertSame('Unterarmstütz', $result['exercises'][2]['name']);
        $this->assertSame(3, $result['exercises'][2]['sets']);
        $this->assertSame('45 s', $result['exercises'][2]['reps']);
    }

    public function test_exercises_without_the_equipment_are_dropped_in_the_plan(): void
    {
        $result = $this->catalog()->normalize(
            [['key' => 'kettlebell_swing'], ['key' => 'squat']],
            'strength', '2026-11-10', ['equipment' => [], 'experience' => 'beginner'],
        );

        $this->assertSame(['squat'], array_column($result['exercises'], 'key'));
    }

    public function test_nothing_usable_gives_the_default_program(): void
    {
        $context = ['equipment' => [], 'experience' => 'beginner'];
        $a = $this->catalog()->normalize([['name' => 'Erfundene Übung']], 'strength', '2026-11-10', $context);
        $b = $this->catalog()->normalize([], 'strength', '2026-11-10', $context);

        $this->assertTrue($a['defaulted']);
        $this->assertSame($a['exercises'], $b['exercises'], 'gleiches Datum, gleiches Programm');
        $this->assertCount(5, $a['exercises']);

        $groups = StrengthExercise::whereIn('key', array_column($a['exercises'], 'key'))->pluck('group')->sort()->values()->all();
        $this->assertSame(['beine', 'huefte', 'oberkoerper', 'rumpf', 'waden'], $groups);

        $mobility = $this->catalog()->defaultProgram('mobility', '2026-11-10', $context);
        $this->assertSame(['mobilitaet'], StrengthExercise::whereIn('key', array_column($mobility, 'key'))->pluck('group')->unique()->values()->all());
    }

    public function test_present_adds_image_instructions_and_credit(): void
    {
        $shown = $this->catalog()->present([
            ['key' => 'goblet_squat', 'name' => 'Goblet Squat', 'sets' => 3, 'reps' => '10'],
            ['key' => 'glute_bridge', 'name' => 'Glute Bridge', 'sets' => 3, 'reps' => '15'],
            ['name' => 'Alte freie Übung', 'sets' => 2, 'reps' => '8'],
        ]);

        $this->assertStringContainsString('/exercise-images/', $shown[0]['image_url']);
        $this->assertSame('Bild: philip, CC-BY-SA 4.0, wger.de', $shown[0]['credit']);
        $this->assertNotEmpty($shown[0]['instructions']);
        $this->assertSame(3, $shown[0]['sets']);

        $this->assertNull($shown[1]['image_url'], 'ohne Bild keine Bildquelle');
        $this->assertNull($shown[1]['credit']);

        $this->assertSame(['name' => 'Alte freie Übung', 'sets' => 2, 'reps' => '8'], $shown[2]);
    }

    public function test_the_image_route_serves_the_stored_jpeg(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);
        $exercise = StrengthExercise::where('key', 'goblet_squat')->first();

        $this->get(route('exercise-images.show', $exercise))->assertRedirect();

        $res = $this->actingAs($user)->get(route('exercise-images.show', $exercise));
        $res->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringStartsWith("\xFF\xD8", $res->getContent());

        $glute = StrengthExercise::where('key', 'glute_bridge')->first();
        $this->actingAs($user)->get(route('exercise-images.show', $glute))->assertNotFound();
    }

    public function test_the_validator_replaces_invented_exercises(): void
    {
        $date = '2026-11-10';
        $skeleton = [
            'days' => [$date => [
                'available' => true, 'finalized' => false, 'max_min' => 60,
                'slots' => [['type' => 'strength', 'hard' => false, 'max_min' => 45]],
            ]],
            'strength' => ['equipment' => [], 'experience' => 'beginner'],
        ];

        $out = app(TrainingPlanValidator::class)->validate([[
            'date' => $date, 'type' => 'strength', 'title' => 'Kraft', 'description' => 'x',
            'distance_km' => 0, 'duration_min' => 40, 'intensity' => 'medium',
            'exercises' => [
                ['name' => 'Turkish Get-up', 'sets' => 3, 'reps' => '3'],
                ['key' => 'wall_sit', 'sets' => 2, 'reps' => '60 s'],
            ],
        ]], $skeleton);

        $strength = collect($out['sessions'])->firstWhere('type', 'strength');
        $this->assertSame(['wall_sit'], array_column($strength['exercises'], 'key'));
        $this->assertSame('60 s', $strength['exercises'][0]['reps']);
        $this->assertTrue(collect($out['report'])->contains(fn ($r) => str_contains($r, 'Turkish Get-up')));
    }

    public function test_a_restored_strength_slot_gets_the_default_program(): void
    {
        $date = '2026-11-10';
        $skeleton = [
            'days' => [$date => [
                'available' => true, 'finalized' => false, 'max_min' => 60,
                'slots' => [['type' => 'strength', 'hard' => false, 'max_min' => 45]],
            ]],
            'strength' => ['equipment' => ['dumbbells'], 'experience' => 'intermediate'],
        ];

        $out = app(TrainingPlanValidator::class)->validate([], $skeleton);

        $strength = collect($out['sessions'])->firstWhere('type', 'strength');
        $this->assertSame('Krafttraining', $strength['title']);
        $this->assertCount(5, $strength['exercises']);
    }

    public function test_the_coach_gets_catalog_exercises_or_the_default_program(): void
    {
        $user = User::factory()->create();
        $session = new TrainingSession([
            'user_id' => $user->id, 'type' => 'strength', 'planned_date' => '2026-11-10',
            'title' => 'Kraft', 'status' => 'planned',
        ]);

        $this->catalog()->applyTo($session, [['key' => 'kettlebell_swing', 'sets' => 5, 'reps' => '20']]);
        $this->assertSame('kettlebell_swing', $session->exercises[0]['key'], 'im Chat ausdrücklich verlangt — gilt auch ohne Kettlebell im Profil');
        $this->assertSame(5, $session->exercises[0]['sets']);

        $session->exercises = null;
        $this->catalog()->applyTo($session);
        $this->assertNotEmpty($session->exercises);

        $session->type = 'easy_run';
        $this->catalog()->applyTo($session);
        $this->assertNull($session->exercises);
    }

    public function test_only_admins_reach_the_catalog(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/strength-exercises')->assertForbidden();

        $admin = User::factory()->create(['is_admin' => true]);
        $props = $this->actingAs($admin)->get('/admin/strength-exercises')->assertOk()->viewData('page')['props'];
        $this->assertCount(49, $props['exercises']);
    }

    public function test_admin_creates_an_exercise_with_a_wger_image(): void
    {
        Cache::forget(\App\Services\Strength\WgerClient::CACHE_KEY);
        $png = $this->png();

        Http::fake([
            'wger.de/api/v2/exerciseinfo/*' => Http::response(['next' => null, 'results' => [[
                'id' => 4242,
                'category' => ['name' => 'Legs'],
                'equipment' => [['name' => 'none (bodyweight exercise)']],
                'translations' => [['language' => 2, 'name' => 'Cossack Squat'], ['language' => 1, 'name' => 'Kosakenkniebeuge']],
                'images' => [['image' => 'https://wger.de/media/exercise-images/4242/x.png', 'is_main' => true, 'license' => 2, 'license_author' => 'jemand', 'is_ai_generated' => false]],
            ]]]),
            'wger.de/media/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);

        $search = $this->actingAs($admin)->getJson('/admin/strength-exercises/wger?q=kosak')->assertOk()->json('results');
        $this->assertSame(4242, $search[0]['id']);

        $this->actingAs($admin)->post('/admin/strength-exercises', [
            'name' => 'Kosakenkniebeuge', 'instructions' => 'Breit stehen, seitlich tief.',
            'group' => 'beine', 'equipment' => ['bodyweight'], 'level' => 'intermediate',
            'default_sets' => 3, 'default_reps' => '6 je Seite', 'wger_id' => 4242,
        ])->assertRedirect()->assertSessionHas('success');

        $e = StrengthExercise::where('key', 'kosakenkniebeuge')->firstOrFail();
        $this->assertSame('CC-BY-SA 4.0', $e->license);
        $this->assertSame('jemand', $e->license_author);
        $this->assertNotNull($e->image);
        $this->assertStringStartsWith("\xFF\xD8", base64_decode($e->image->data), 'als JPEG gespeichert');
    }

    public function test_admin_switches_an_exercise_off_instead_of_deleting(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $e = StrengthExercise::where('key', 'squat')->first();

        $this->actingAs($admin)->patch("/admin/strength-exercises/{$e->id}/toggle")->assertRedirect();
        $this->assertFalse($e->fresh()->active);
        $this->assertSame(49, StrengthExercise::count());
    }

    public function test_youtube_links_become_a_video_id(): void
    {
        foreach ([
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42s',
            'https://youtu.be/dQw4w9WgXcQ?si=abc',
            'youtube.com/shorts/dQw4w9WgXcQ',
            'https://m.youtube.com/embed/dQw4w9WgXcQ',
            'dQw4w9WgXcQ',
        ] as $link) {
            $this->assertSame('dQw4w9WgXcQ', StrengthExercise::youtubeId($link), $link);
        }

        $this->assertNull(StrengthExercise::youtubeId('https://vimeo.com/123456789'));
        $this->assertNull(StrengthExercise::youtubeId('https://evil.example/watch?v=dQw4w9WgXcQ'));
        $this->assertNull(StrengthExercise::youtubeId(''));
    }

    public function test_admin_adds_a_video_and_the_session_shows_it(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $e = StrengthExercise::where('key', 'squat')->first();
        $form = [
            'name' => $e->name, 'group' => $e->group, 'equipment' => $e->equipment, 'level' => $e->level,
            'default_sets' => 3, 'default_reps' => '10',
        ];

        $this->actingAs($admin)->patch("/admin/strength-exercises/{$e->id}", $form + ['youtube' => 'https://vimeo.com/1'])
            ->assertSessionHasErrors('youtube');

        $this->actingAs($admin)->patch("/admin/strength-exercises/{$e->id}", $form + ['youtube' => 'https://youtu.be/dQw4w9WgXcQ'])
            ->assertSessionHasNoErrors();
        $this->assertSame('dQw4w9WgXcQ', $e->fresh()->youtube_id);

        $catalog = app(StrengthCatalog::class);
        $catalog->forget();
        $this->assertSame('dQw4w9WgXcQ', $catalog->present([['key' => 'squat', 'sets' => 3, 'reps' => '10']])[0]['youtube_id']);
    }

    public function test_admin_sets_the_muscles_of_an_exercise(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $e = StrengthExercise::where('key', 'squat')->first();
        $form = [
            'name' => $e->name, 'group' => $e->group, 'equipment' => $e->equipment, 'level' => $e->level,
            'default_sets' => 3, 'default_reps' => '10',
        ];

        $this->actingAs($admin)->patch("/admin/strength-exercises/{$e->id}", $form + ['muscles' => ['primary' => ['nose']]])
            ->assertSessionHasErrors('muscles.primary.0');

        // Ein Muskel in beiden Listen zählt als Hauptmuskel.
        $this->actingAs($admin)->patch("/admin/strength-exercises/{$e->id}", $form + ['muscles' => [
            'primary' => ['quads', 'glutes'], 'secondary' => ['glutes', 'calves'],
        ]])->assertSessionHasNoErrors();
        $this->assertSame(['primary' => ['quads', 'glutes'], 'secondary' => ['calves']], $e->fresh()->muscles);

        // Ein Formular ohne Muskeln lässt sie stehen.
        $this->actingAs($admin)->patch("/admin/strength-exercises/{$e->id}", $form)->assertSessionHasNoErrors();
        $this->assertSame(['quads', 'glutes'], $e->fresh()->muscles['primary']);
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(800, 600);
        ob_start();
        imagepng($img);

        return ob_get_clean();
    }
}
