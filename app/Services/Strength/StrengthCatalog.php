<?php

namespace App\Services\Strength;

use App\Models\StrengthExercise;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Der Kraft-Katalog: welche Übungen es gibt, welche zum Athleten passen, und
 * wie eine Übungsliste an einer Einheit aussieht.
 *
 * Das Modell wählt Schlüssel und legt Sätze, Wiederholungen und Last fest
 * (Entscheidung von Jan, 05.11.2026: keine Klammer um die Zahlen). Was es
 * nicht wählen darf, sind Übungen außerhalb des Katalogs — ein freier Name
 * hat kein Bild und keine Ausführung. Solche Einträge fallen weg; bleibt
 * nichts, gilt das Grundprogramm.
 *
 * Gespeichert wird an der Einheit `{key, name, sets, reps, load, note}`. Bild,
 * Ausführung und Quelle kommen beim Anzeigen dazu ({@see present()}), damit
 * eine Änderung im Admin auch an alten Einheiten ankommt.
 */
class StrengthCatalog
{
    public const MAX_EXERCISES = 8;

    /** Welche Gruppen das Grundprogramm je Einheitstyp der Reihe nach füllt. */
    private const PROGRAM = [
        'strength' => ['beine', 'huefte', 'rumpf', 'waden', 'oberkoerper'],
        'core'     => ['rumpf', 'rumpf', 'huefte', 'rumpf'],
        'mobility' => ['mobilitaet', 'mobilitaet', 'mobilitaet', 'mobilitaet'],
    ];

    private const LEVEL_RANK = ['beginner' => 1, 'intermediate' => 2, 'advanced' => 3];

    /** @var Collection<int,StrengthExercise>|null */
    private ?Collection $all = null;

    /** Alle Übungen, auch inaktive (für die Anzeige alter Einheiten). */
    public function all(): Collection
    {
        if ($this->all === null) {
            try {
                $this->all = StrengthExercise::orderBy('sort')->orderBy('id')->get();
            } catch (\Illuminate\Database\QueryException) {
                // Ohne Tabelle (alte Testdatenbank) gibt es keinen Katalog —
                // dann bleibt alles, wie das Modell es geschrieben hat.
                $this->all = collect();
            }
        }

        return $this->all;
    }

    public function forget(): void
    {
        $this->all = null;
    }

    /**
     * Aktive Übungen, die zum Equipment und zur Erfahrung passen.
     * Körpergewicht geht immer; das Studio hat Hanteln, Kettlebell und Bänder.
     *
     * @param  list<string>  $equipment
     */
    public function available(array $equipment = [], ?string $level = null): Collection
    {
        $have = $this->equipmentSet($equipment);
        $rank = self::LEVEL_RANK[$level ?? 'intermediate'] ?? 2;

        return $this->all()
            ->filter(fn (StrengthExercise $e) => $e->active)
            ->filter(fn (StrengthExercise $e) => array_intersect($e->equipment ?? [], $have) !== [])
            ->filter(fn (StrengthExercise $e) => (self::LEVEL_RANK[$e->level] ?? 1) <= $rank)
            ->values();
    }

    /** Die Liste für den Plan-Prompt: je Gruppe eine Zeile mit Schlüssel (Name). */
    public function promptList(array $equipment = [], ?string $level = null): string
    {
        return $this->available($equipment, $level)
            ->groupBy('group')
            ->map(fn ($items, $group) => '- '.(StrengthExercise::GROUPS[$group] ?? $group).': '
                .$items->map(fn ($e) => "{$e->key} ({$e->name})")->implode(', '))
            ->implode("\n");
    }

    /**
     * Übungen aus der Modellantwort auf den Katalog bringen.
     *
     * @param  array  $context  equipment, experience (aus dem Gerüst); fehlt
     *                          es, zählt jede aktive Übung des Katalogs.
     * @return array{exercises: list<array>, dropped: list<string>, defaulted: bool}
     */
    public function normalize(mixed $exercises, string $type, ?string $date = null, ?array $context = null): array
    {
        if ($this->all()->isEmpty()) {
            return ['exercises' => is_array($exercises) ? array_values($exercises) : [], 'dropped' => [], 'defaulted' => false];
        }

        $allowed = $context === null
            ? $this->all()->filter(fn ($e) => $e->active)
            : $this->available($context['equipment'] ?? [], $context['experience'] ?? null);
        $byKey = $allowed->keyBy('key');

        $out = [];
        $dropped = [];
        foreach (is_array($exercises) ? $exercises : [] as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $match = $this->match($raw, $byKey);
            if (! $match) {
                $dropped[] = (string) ($raw['key'] ?? $raw['name'] ?? '?');
                continue;
            }
            if (isset($out[$match->key]) || count($out) >= self::MAX_EXERCISES) {
                continue;
            }
            $out[$match->key] = $this->entry($match, $raw);
        }

        if ($out === []) {
            return ['exercises' => $this->defaultProgram($type, $date, $context), 'dropped' => $dropped, 'defaulted' => true];
        }

        return ['exercises' => array_values($out), 'dropped' => $dropped, 'defaulted' => false];
    }

    /**
     * Grundprogramm, wenn das Modell nichts Brauchbares liefert: je Gruppe
     * eine Übung, rotiert nach Datum, damit nicht jede Woche dieselbe kommt.
     */
    public function defaultProgram(string $type, ?string $date = null, ?array $context = null): array
    {
        $pool = $context === null
            ? $this->all()->filter(fn ($e) => $e->active)->values()
            : $this->available($context['equipment'] ?? [], $context['experience'] ?? null);
        if ($pool->isEmpty()) {
            return [];
        }

        $seed = $date ? (int) CarbonImmutable::parse($date)->format('z') : 0;
        $groups = self::PROGRAM[$type] ?? self::PROGRAM['strength'];
        $picked = [];

        foreach ($groups as $i => $group) {
            $candidates = $pool->where('group', $group)->reject(fn ($e) => isset($picked[$e->key]))->values();
            if ($candidates->isEmpty()) {
                continue;
            }
            $e = $candidates[($seed + $i) % $candidates->count()];
            $picked[$e->key] = $this->entry($e, []);
        }

        return array_values($picked);
    }

    /**
     * Übungen einer Einheit für die Anzeige: Bild, Ausführung, Quelle.
     * Ältere Einheiten ohne Schlüssel werden über den Namen gefunden, sonst
     * bleiben sie, wie sie sind.
     */
    public function present(mixed $exercises): array
    {
        if (! is_array($exercises) || $exercises === []) {
            return [];
        }

        $all = $this->all();
        $byKey = $all->keyBy('key');
        $versions = $this->imageVersions();

        return array_values(array_map(function ($ex) use ($byKey, $versions) {
            if (! is_array($ex)) {
                return $ex;
            }
            $e = $this->match($ex, $byKey);
            if (! $e) {
                return $ex;
            }

            return array_merge($ex, [
                'key' => $e->key,
                'name' => $e->name,
                'instructions' => $e->instructions,
                'group' => StrengthExercise::GROUPS[$e->group] ?? $e->group,
                'image_url' => $e->imageUrl($versions[$e->id] ?? null),
                'credit' => isset($versions[$e->id]) ? $e->credit() : null,
                'source_url' => isset($versions[$e->id]) ? $e->wgerUrl() : null,
            ]);
        }, $exercises));
    }

    /**
     * Übungen an einer Einheit setzen, die außerhalb des Plans entsteht oder
     * sich ändert (Coach-Chat, angenommener Vorschlag).
     *
     * Kein Kraft-Typ → keine Übungen. Nennt der Coach Übungen, gilt der ganze
     * aktive Katalog — wer im Chat ausdrücklich Kettlebell-Swings verlangt,
     * bekommt sie, auch wenn die Kettlebell nicht im Profil steht. Ohne
     * Angabe bleiben vorhandene Übungen, sonst das Grundprogramm passend zum
     * Profil.
     */
    public function applyTo(TrainingSession $session, mixed $exercises = null): void
    {
        if (! in_array($session->type, ['strength', 'core', 'mobility'], true)) {
            $session->exercises = null;

            return;
        }

        $date = $session->planned_date ? CarbonImmutable::parse($session->planned_date)->toDateString() : null;

        if (is_array($exercises) && $exercises !== []) {
            $session->exercises = $this->normalize($exercises, $session->type, $date)['exercises'] ?: null;

            return;
        }

        if (! empty($session->exercises) && ! $session->isDirty('type')) {
            return;
        }

        $rp = $session->user?->runnerProfile;
        $session->exercises = $this->defaultProgram($session->type, $date, [
            'equipment'  => $rp?->strength_equipment ?? [],
            'experience' => $rp?->strength_experience,
        ]) ?: null;
    }

    /** @return list<string> */
    private function equipmentSet(array $equipment): array
    {
        $have = array_values(array_unique(array_merge(['bodyweight'], $equipment)));
        if (in_array('gym', $have, true)) {
            $have = array_values(array_unique(array_merge($have, ['dumbbells', 'kettlebell', 'band'])));
        }

        return $have;
    }

    private function match(array $raw, Collection $byKey): ?StrengthExercise
    {
        $key = Str::of((string) ($raw['key'] ?? ''))->lower()->trim()->toString();
        if ($key !== '' && $byKey->has($key)) {
            return $byKey[$key];
        }

        $name = $this->simplify((string) ($raw['name'] ?? $raw['key'] ?? ''));
        if ($name === '') {
            return null;
        }

        return $byKey->first(fn (StrengthExercise $e) => $this->simplify($e->name) === $name || $this->simplify($e->key) === $name);
    }

    private function simplify(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($s)));
    }

    private function entry(StrengthExercise $e, array $raw): array
    {
        $sets = (int) ($raw['sets'] ?? 0);
        $reps = trim((string) ($raw['reps'] ?? ''));
        $load = trim((string) ($raw['load'] ?? ''));
        $note = trim((string) ($raw['note'] ?? ''));

        return [
            'key'  => $e->key,
            'name' => $e->name,
            'sets' => $sets >= 1 && $sets <= 10 ? $sets : $e->default_sets,
            'reps' => $reps !== '' ? Str::limit($reps, 30, '') : $e->default_reps,
            'load' => $load !== '' ? Str::limit($load, 40, '') : $e->default_load,
            'note' => $note !== '' ? Str::limit($note, 140) : null,
        ];
    }

    /** strength_exercise_id → Zeitstempel des Bildes (ohne die Bilddaten zu laden). */
    private function imageVersions(): array
    {
        try {
            return DB::table('strength_exercise_images')
                ->pluck('updated_at', 'strength_exercise_id')
                ->map(fn ($t) => $t ? strtotime((string) $t) : 1)
                ->all();
        } catch (\Illuminate\Database\QueryException) {
            return [];
        }
    }
}
