<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StrengthExercise;
use App\Services\Strength\StrengthCatalog;
use App\Services\Strength\WgerClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Der Kraft-Katalog im Admin (05.11.2026, nur Admins — Entscheidung von Jan).
 *
 * Übungen anlegen, ändern, aus wger übernehmen. Gelöscht wird nie: alte
 * Einheiten verweisen über den Schlüssel auf die Übung, und eine gelöschte
 * Übung stünde dort ohne Bild und Ausführung. „Aus" nimmt sie nur aus der
 * Auswahl für neue Einheiten.
 */
class AdminStrengthExerciseController extends Controller
{
    public function index(StrengthCatalog $catalog): Response
    {
        $exercises = $catalog->present(
            $catalog->all()->map(fn (StrengthExercise $e) => ['key' => $e->key])->all()
        );
        $byKey = $catalog->all()->keyBy('key');

        return Inertia::render('Admin/StrengthExercises/Index', [
            'exercises' => collect($exercises)->map(function ($p) use ($byKey) {
                $e = $byKey[$p['key']];

                return [
                    'id'             => $e->id,
                    'key'            => $e->key,
                    'name'           => $e->name,
                    'instructions'   => $e->instructions,
                    'group'          => $e->group,
                    'equipment'      => $e->equipment ?? [],
                    'level'          => $e->level,
                    'default_sets'   => $e->default_sets,
                    'default_reps'   => $e->default_reps,
                    'default_load'   => $e->default_load,
                    'wger_id'        => $e->wger_id,
                    'license'        => $e->license,
                    'license_author' => $e->license_author,
                    'active'         => $e->active,
                    'garmin_category' => $e->garmin_category,
                    'garmin_name'    => $e->garmin_name,
                    'youtube_id'     => $e->youtube_id,
                    'image_url'      => $p['image_url'] ?? null,
                    'credit'         => $p['credit'] ?? null,
                ];
            })->values(),
            'groups'    => StrengthExercise::GROUPS,
            'equipment' => StrengthExercise::EQUIPMENT,
            'levels'    => StrengthExercise::LEVELS,
            'garmin'    => StrengthExercise::garminExercises(),
        ]);
    }

    public function store(Request $request, WgerClient $wger): RedirectResponse
    {
        $data = $this->validated($request);

        $exercise = DB::transaction(function () use ($data) {
            return StrengthExercise::create($data + [
                'key'    => $this->uniqueKey($data['name']),
                'active' => true,
                'sort'   => (int) StrengthExercise::max('sort') + 10,
            ]);
        });

        $message = "„{$exercise->name}“ angelegt.";
        if ($exercise->wger_id) {
            $message .= $this->importImage($exercise, $wger) ? ' Bild von wger übernommen.' : ' Bei wger gibt es dazu kein Bild.';
        }

        Log::info('Kraftübung angelegt', ['key' => $exercise->key, 'admin' => $request->user()->id]);

        return back()->with('success', $message);
    }

    public function update(Request $request, StrengthExercise $exercise): RedirectResponse
    {
        $data = $this->validated($request);
        $wgerChanged = ($data['wger_id'] ?? null) !== $exercise->wger_id;

        $exercise->update($data);

        // Andere wger-Übung → das alte Bild gehört nicht mehr dazu.
        if ($wgerChanged) {
            $exercise->image()->delete();
            if (! $exercise->wger_id) {
                $exercise->update(['license' => null, 'license_author' => null]);
            }
        }

        return back()->with('success', "„{$exercise->name}“ gespeichert.".($wgerChanged && $exercise->wger_id ? ' Bild bitte neu holen.' : ''));
    }

    public function toggle(StrengthExercise $exercise): RedirectResponse
    {
        $exercise->update(['active' => ! $exercise->active]);

        return back()->with('success', $exercise->active
            ? "„{$exercise->name}“ ist wieder in der Auswahl."
            : "„{$exercise->name}“ kommt in neuen Einheiten nicht mehr vor.");
    }

    /** Bild (neu) von wger holen. */
    public function refreshImage(StrengthExercise $exercise, WgerClient $wger): RedirectResponse
    {
        if (! $exercise->wger_id) {
            return back()->with('error', 'Diese Übung ist mit keiner wger-Übung verknüpft.');
        }

        return $this->importImage($exercise, $wger)
            ? back()->with('success', "Bild für „{$exercise->name}“ übernommen.")
            : back()->with('error', 'Bei wger gibt es zu dieser Übung kein Bild, oder es ließ sich nicht lesen.');
    }

    public function removeImage(StrengthExercise $exercise): RedirectResponse
    {
        $exercise->image()->delete();

        return back()->with('success', "Bild von „{$exercise->name}“ entfernt.");
    }

    /** Suche in wger, als JSON für das Suchfeld. */
    public function wgerSearch(Request $request, WgerClient $wger): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        try {
            return response()->json(['results' => $wger->search($q)]);
        } catch (\Throwable $e) {
            Log::warning('wger-Suche fehlgeschlagen', ['error' => $e->getMessage()]);

            return response()->json(['results' => [], 'error' => 'wger ist gerade nicht erreichbar.'], 502);
        }
    }

    private function importImage(StrengthExercise $exercise, WgerClient $wger): bool
    {
        try {
            $image = $wger->downloadImage($exercise->wger_id);
        } catch (\Throwable $e) {
            Log::warning('wger-Bild nicht geladen', ['wger_id' => $exercise->wger_id, 'error' => $e->getMessage()]);

            return false;
        }

        if (! $image) {
            return false;
        }

        $exercise->image()->updateOrCreate([], ['mime' => $image['mime'], 'data' => $image['data']]);
        $exercise->update(['license' => $image['license'], 'license_author' => $image['author']]);

        return true;
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name'           => 'required|string|max:120',
            'instructions'   => 'nullable|string|max:1000',
            'group'          => ['required', Rule::in(array_keys(StrengthExercise::GROUPS))],
            'equipment'      => 'required|array|min:1',
            'equipment.*'    => [Rule::in(array_keys(StrengthExercise::EQUIPMENT))],
            'level'          => ['required', Rule::in(array_keys(StrengthExercise::LEVELS))],
            'default_sets'   => 'required|integer|min:1|max:10',
            'default_reps'   => 'required|string|max:30',
            'default_load'   => 'nullable|string|max:60',
            'wger_id'        => 'nullable|integer|min:1',
            'garmin_category' => ['nullable', Rule::in(array_keys(StrengthExercise::garminExercises()))],
            'garmin_name'    => ['nullable', 'required_with:garmin_category', function ($attr, $value, $fail) use ($request) {
                $names = StrengthExercise::garminExercises()[$request->input('garmin_category')] ?? [];
                if ($value !== null && ! in_array($value, $names, true)) {
                    $fail('Diesen Namen kennt Garmin in der Kategorie nicht.');
                }
            }],
            'youtube'        => ['nullable', 'string', 'max:300', function ($attr, $value, $fail) {
                if (StrengthExercise::youtubeId($value) === null) {
                    $fail('Das ist kein YouTube-Link.');
                }
            }],
        ]);

        // Gespeichert wird nur die Video-ID, egal welche Form der Link hatte.
        $data['youtube_id'] = StrengthExercise::youtubeId($data['youtube'] ?? null);
        unset($data['youtube']);

        return $data;
    }

    private function uniqueKey(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 50, '') ?: 'uebung';
        $key = $base;
        for ($i = 2; StrengthExercise::where('key', $key)->exists(); $i++) {
            $key = "{$base}_{$i}";
        }

        return $key;
    }
}
