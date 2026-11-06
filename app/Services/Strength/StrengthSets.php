<?php

namespace App\Services\Strength;

use App\Models\StrengthExercise;
use App\Models\StrengthExerciseAlias;
use App\Models\TrainingSession;
use App\Services\Garmin\GarminStrengthBuilder;
use Illuminate\Support\Collection;

/**
 * Die Sätze eines Krafttrainings, wie die Uhr sie aufgezeichnet hat.
 *
 * Quelle ist die FIT-Datei (Nachricht `set`): je Satz Kategorie und Übung als
 * Zahl aus Garmins Übungsliste, Wiederholungen, Gewicht und Dauer. Garmin
 * schreibt das in jede Krafteinheit — auch ohne Workout, dann rät die Uhr die
 * Übung oder schreibt „unbekannt" (65534). Strava liest genau diese Sätze.
 *
 * Gespeichert wird in `activities.strength_sets` nur die rohe Liste der
 * aktiven Sätze, mit Garmins Namen. Was die Seite zeigt — Katalog-Übung,
 * Vergleich mit dem Plan, Muskeln — entsteht beim Anzeigen, damit eine
 * Änderung im Katalog auch alte Einheiten erreicht.
 *
 * Zuordnung zum Katalog nur über `strength_exercise_aliases`, nie über
 * Ähnlichkeit: dieselbe Zuordnung, mit der Zone3 das Workout auf die Uhr
 * schickt, also kommt eine geplante Übung als dieselbe zurück.
 */
class StrengthSets
{
    /** FIT: Übung unbekannt. */
    private const UNKNOWN_CATEGORY = 65534;

    /** FIT `set_type`: 0 Pause, 1 aktiv. */
    private const SET_ACTIVE = 1;

    /**
     * Garmins Übungsliste mit Nummern (FIT-Profil aus `@garmin/fitsdk`).
     *
     * @return array<string, array{category: string, exercises: array<string, string>}>
     */
    public static function garminIds(): array
    {
        static $ids = null;

        return $ids ??= json_decode(file_get_contents(database_path('data/strength/garmin-exercise-ids.json')), true);
    }

    /**
     * Die `set`-Nachrichten aus `FitDecoder` als gespeicherte Liste.
     *
     * Jede Zeile ist anbieterneutral: `source` sagt, von welcher Uhr sie kam,
     * `alias` + `key` sind die Kennung, über die `strength_exercise_aliases`
     * die Katalog-Übung findet. Die FIT-Datei nummeriert Übungen nach dem
     * FIT-Profil — das ist Garmins Liste, auch wenn COROS die Datei schreibt.
     * `name` ist, was die Uhr anzeigt, `group` die grobe Gruppe für die
     * Muskeln, wenn die Übung nicht im Katalog steht.
     *
     * @return list<array{source: string, alias: ?string, key: ?string, name: ?string, group: ?string, reps: ?int, weight_kg: ?float, duration_s: ?int, start: ?int}>
     */
    public static function fromFit(array $sets, string $source): array
    {
        $out = [];

        foreach ($sets as $set) {
            if (($set['set_type'] ?? self::SET_ACTIVE) !== self::SET_ACTIVE) {
                continue;
            }

            $reps     = isset($set['repetitions']) ? (int) $set['repetitions'] : null;
            $duration = isset($set['duration']) ? (int) round($set['duration']) : null;
            if (! $reps && ! $duration) {
                continue;
            }

            [$category, $name] = self::names($set['category'] ?? null, $set['category_subtype'] ?? null);

            $out[] = self::row($source, [
                'alias'      => StrengthExerciseAlias::GARMIN,
                'key'        => StrengthExerciseAlias::garminKey($category, $name),
                'name'       => $name ? self::humanize($name) : ($category ? self::humanize($category) : null),
                'group'      => $category,
                'reps'       => $reps,
                'weight_kg'  => isset($set['weight']) && $set['weight'] > 0 ? (float) $set['weight'] : null,
                'duration_s' => $duration,
                'start'      => $set['start_time'] ?? $set['timestamp'] ?? null,
            ]);
        }

        usort($out, fn ($a, $b) => ($a['start'] ?? 0) <=> ($b['start'] ?? 0));

        return $out;
    }

    /** Eine Satzzeile in der gespeicherten Form — auch für Quellen ohne FIT (COROS über MCP). */
    public static function row(string $source, array $set): array
    {
        return [
            'source'     => $source,
            'alias'      => $set['alias'] ?? null,
            'key'        => $set['key'] ?? null,
            'name'       => $set['name'] ?? null,
            'group'      => $set['group'] ?? null,
            'reps'       => ($set['reps'] ?? null) ?: null,
            'weight_kg'  => isset($set['weight_kg']) ? round((float) $set['weight_kg'], 1) : null,
            'duration_s' => ($set['duration_s'] ?? null) ?: null,
            'start'      => $set['start'] ?? null,
        ];
    }

    /** @return array{0: ?string, 1: ?string} Garmins Kategorie und Übung als Namen */
    public static function names(?int $category, ?int $subtype): array
    {
        if ($category === null || $category === self::UNKNOWN_CATEGORY) {
            return [null, null];
        }

        $entry = self::garminIds()[(string) $category] ?? null;
        if (! $entry) {
            return [null, null];
        }

        return [$entry['category'], $subtype !== null ? ($entry['exercises'][(string) $subtype] ?? null) : null];
    }

    /** „KETTLEBELL_SWING" → „Kettlebell Swing". */
    public static function humanize(string $garminName): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $garminName)));
    }

    public function __construct(private readonly StrengthCatalog $catalog) {}

    /**
     * Alles, was die Aktivitätsseite braucht.
     *
     * @return array{exercises: list<array>, totals: array, comparison: ?array, muscles: array<string, float>}|null
     */
    public function present(?array $sets, ?TrainingSession $session = null): ?array
    {
        if (! $sets) {
            return null;
        }

        $planned  = $session && in_array($session->type, GarminStrengthBuilder::TYPES, true)
            ? $this->catalog->present($session->exercises ?? [])
            : [];
        $plannedKeys = collect($planned)->pluck('key')->filter()->all();

        $catalog   = $this->catalog->all();
        $exercises = $this->group($sets, $catalog, $plannedKeys);

        return [
            'exercises'  => $exercises,
            'totals'     => [
                'sets' => count($sets),
                'reps' => array_sum(array_map(fn ($s) => (int) ($s['reps'] ?? 0), $sets)),
            ],
            'comparison' => $planned !== [] ? $this->compare($planned, $exercises) : null,
            'muscles'    => Muscles::load($exercises),
        ];
    }

    /**
     * Aufeinanderfolgende Sätze derselben Übung zu einer Zeile. Dieselbe
     * Übung später noch einmal (Zirkel) wird eine eigene Zeile, wie auf der
     * Uhr.
     */
    private function group(array $sets, Collection $catalog, array $plannedKeys): array
    {
        $groups = [];

        foreach ($sets as $set) {
            $id   = ($set['alias'] ?? '') . ':' . ($set['key'] ?? $set['name'] ?? '');
            $last = array_key_last($groups);

            if ($last !== null && $groups[$last]['_id'] === $id) {
                $groups[$last]['sets_detail'][] = $this->setRow($set);
                continue;
            }

            $groups[] = ['_id' => $id, 'alias' => $set['alias'] ?? null, 'key' => $set['key'] ?? null, 'name' => $set['name'] ?? null, 'group' => $set['group'] ?? null, 'sets_detail' => [$this->setRow($set)]];
        }

        return array_map(function (array $g) use ($catalog, $plannedKeys) {
            $match   = $this->catalogFor($g['alias'], $g['key'], $catalog, $plannedKeys);
            $present = $match ? ($this->catalog->present([['key' => $match->key]])[0] ?? []) : [];
            $label   = $match?->name ?? $g['name'] ?? 'Unbekannt';

            return [
                'key'       => $match?->key,
                'label'     => $label,
                'known'     => $g['name'] !== null,
                'device'    => $g['name'],
                'image_url' => $present['image_url'] ?? null,
                'sets'      => count($g['sets_detail']),
                'reps'      => array_sum(array_map(fn ($s) => (int) ($s['reps'] ?? 0), $g['sets_detail'])),
                'detail'    => $g['sets_detail'],
                'muscles'   => Muscles::forExercise($match?->muscles, $g['group']),
            ];
        }, $groups);
    }

    private function setRow(array $set): array
    {
        return ['reps' => $set['reps'] ?? null, 'weight_kg' => $set['weight_kg'] ?? null, 'duration_s' => $set['duration_s'] ?? null];
    }

    /**
     * Die Katalog-Übung zur Kennung der Uhr — nur über die Zuordnung, nie
     * über Ähnlichkeit. Ohne Treffer bleibt der Name der Uhr stehen.
     */
    private function catalogFor(?string $alias, ?string $key, Collection $catalog, array $plannedKeys): ?StrengthExercise
    {
        if (! $alias || ! $key) {
            return null;
        }

        $hits = $catalog->filter(fn (StrengthExercise $e) => $e->aliases->contains(
            fn ($a) => $a->provider === $alias && strcasecmp($a->external_key, $key) === 0,
        ));

        return $hits->first(fn ($e) => in_array($e->key, $plannedKeys, true)) ?? $hits->first();
    }

    /**
     * Geplant gegen gemacht, je geplanter Übung. Unbekannte Sätze werden
     * keiner Übung zugeschlagen — wer rät, verfälscht den Vergleich. Sie
     * stehen als „nicht zugeordnet" dabei.
     */
    private function compare(array $planned, array $done): array
    {
        $byKey = [];
        foreach ($done as $ex) {
            if ($ex['key']) {
                $byKey[$ex['key']][] = $ex;
            }
        }

        $rows = [];
        foreach ($planned as $p) {
            $key  = $p['key'] ?? null;
            $hits = $key ? ($byKey[$key] ?? []) : [];
            unset($byKey[$key]);

            [$unit, $amount] = GarminStrengthBuilder::amount($p['reps'] ?? null);
            $plannedSets = max(1, (int) ($p['sets'] ?? 1));
            $doneSets    = array_sum(array_column($hits, 'sets'));

            $rows[] = [
                'key'          => $key,
                'label'        => $p['name'] ?? $key ?? 'Übung',
                'planned'      => trim($plannedSets . ' × ' . ($p['reps'] ?? '')),
                'planned_sets' => $plannedSets,
                'planned_reps' => $unit === 'REPS' ? (int) $amount : null,
                'done_sets'    => $doneSets,
                'done_reps'    => $hits === [] ? null : array_sum(array_column($hits, 'reps')),
                'done'         => $hits === [] ? null : $this->doneText($hits),
                'status'       => match (true) {
                    $doneSets === 0            => 'missing',
                    $doneSets < $plannedSets   => 'partial',
                    default                    => 'done',
                },
            ];
        }

        $extra   = [];
        $unknown = 0;
        foreach ($done as $ex) {
            if (! $ex['key'] && ! $ex['known']) {
                $unknown += $ex['sets'];
            } elseif (! $ex['key'] || isset($byKey[$ex['key']])) {
                $extra[] = ['label' => $ex['label'], 'sets' => $ex['sets'], 'done' => $this->doneText([$ex])];
            }
        }

        return [
            'rows'          => $rows,
            'extra'         => $extra,
            'unknown_sets'  => $unknown,
            'done_count'    => count(array_filter($rows, fn ($r) => $r['status'] === 'done')),
            'planned_count' => count($rows),
        ];
    }

    /** „3 × 10, 12 kg" bzw. „2 × 45 s". */
    private function doneText(array $hits): string
    {
        $detail = array_merge(...array_column($hits, 'detail'));
        $sets   = count($detail);
        $reps   = array_values(array_filter(array_column($detail, 'reps')));
        $weight = array_values(array_filter(array_column($detail, 'weight_kg')));

        if ($reps !== []) {
            $text = count(array_unique($reps)) === 1 && count($reps) === $sets
                ? $sets . ' × ' . $reps[0]
                : implode(' · ', $reps);
        } else {
            $secs = array_values(array_filter(array_column($detail, 'duration_s')));
            $text = $sets . ' × ' . ($secs !== [] ? (int) round(array_sum($secs) / count($secs)) . ' s' : '–');
        }

        if ($weight !== []) {
            $text .= ', ' . str_replace('.', ',', (string) max($weight)) . ' kg';
        }

        return $text;
    }
}
