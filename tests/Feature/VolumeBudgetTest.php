<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Services\TrainingPaceService;
use App\Services\WeeklyPatternService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Das Gerüst kennt den Wochenumfang.
 *
 * Hier lag der folgenschwerste Widerspruch des ganzen Prompts. Das Gerüst
 * belegte die Tage nach VERFÜGBARKEIT und schrieb je Tag „max. 120 min" —
 * das Modell las das als Auftrag. Zwei Abschnitte weiter oben stand
 * gleichzeitig als bindend: „der Wochenumfang darf 35,2 km nicht
 * überschreiten".
 *
 * Für Jans echten Fall hiess das: fünf Einheiten mit zusammen 383 Minuten
 * Laufzeit — rund 71 km — gegen einen Deckel von 35,2 km. Es gab keine
 * Antwort, die beide Vorgaben erfüllt. Das Modell musste eine brechen, und
 * welche, entschied es jedes Mal neu.
 */
class VolumeBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-08-31'; // ein Montag

    private function event(string $distance = 'marathon'): Event
    {
        $user = User::factory()->create();

        return Event::create([
            'user_id'             => $user->id,
            'name'                => 'Berlin Marathon',
            'event_date'          => CarbonImmutable::parse(self::TODAY)->addDays(38),
            'race_distance'       => $distance,
            'priority'            => 'A',
            'target_time_hours'   => 3,
            'target_time_minutes' => 30,
        ]);
    }

    /** Jans echtes Raster: viel Zeit am Wochenende, wenig unter der Woche. */
    private function availability(): array
    {
        return [
            'monday'    => ['available' => true, 'duration_min' => 45],
            'tuesday'   => ['available' => true, 'duration_min' => 60],
            'wednesday' => ['available' => true, 'duration_min' => 45],
            'thursday'  => ['available' => true, 'duration_min' => 60],
            'friday'    => ['available' => true, 'duration_min' => 120],
            'saturday'  => ['available' => true, 'duration_min' => 120],
            'sunday'    => ['available' => true, 'duration_min' => 180],
        ];
    }

    private function build(float $weeklyMax, ?array $longRuns = null, ?array $availability = null): array
    {
        $from = CarbonImmutable::parse(self::TODAY);

        return app(WeeklyPatternService::class)->build(
            $this->event(),
            $from,
            $from->addDays(6),
            $availability ?? $this->availability(),
            [], [], null,
            $longRuns,
            ['has_data' => true, 'next_week_max' => $weeklyMax],
            330, // Planungstempo 5:30 min/km in Sekunden
        );
    }

    /** Die geplanten Laufkilometer einer Woche. */
    private function plannedKm(array $skeleton): float
    {
        $km = 0.0;

        foreach ($skeleton['days'] as $day) {
            foreach ($day['slots'] ?? [] as $slot) {
                if (in_array($slot['type'], WeeklyPatternService::RUN_SLOT_TYPES, true)) {
                    $km += (float) ($slot['target_km'] ?? 0);
                }
            }
        }

        return round($km, 1);
    }

    private function runSlots(array $skeleton): array
    {
        return collect($skeleton['days'])
            ->flatMap(fn ($d) => $d['slots'] ?? [])
            ->filter(fn ($s) => in_array($s['type'], WeeklyPatternService::RUN_SLOT_TYPES, true))
            ->values()
            ->all();
    }

    // ── Der Deckel wird eingehalten ──────────────────────────────────────

    public function test_the_planned_volume_respects_the_ceiling(): void
    {
        $skeleton = $this->build(35.2);

        $this->assertLessThanOrEqual(
            35.2 * 1.05, // etwas Luft für Rundung
            $this->plannedKm($skeleton),
            'Das Gerüst darf nicht mehr Umfang verlangen, als der Deckel erlaubt',
        );
    }

    /** Jeder Laufeinheit steht ihr Zielumfang daneben, nicht nur eine Obergrenze. */
    public function test_every_run_slot_carries_a_target(): void
    {
        foreach ($this->runSlots($this->build(35.2)) as $slot) {
            $this->assertArrayHasKey('target_km', $slot, "Slot {$slot['type']} ohne Zielumfang");
            $this->assertArrayHasKey('target_min', $slot);
            $this->assertGreaterThan(0, $slot['target_min']);
        }
    }

    /** Die Verfügbarkeit bleibt Obergrenze — sie wird nie zum Ziel. */
    public function test_availability_stays_a_ceiling(): void
    {
        // Reichlich Umfang, aber die Tage sind kurz.
        $skeleton = $this->build(200.0);

        foreach ($skeleton['days'] as $date => $day) {
            foreach ($day['slots'] ?? [] as $slot) {
                if (! in_array($slot['type'], WeeklyPatternService::RUN_SLOT_TYPES, true)) {
                    continue;
                }

                $this->assertLessThanOrEqual(
                    $day['budget_min'],
                    $slot['target_min'],
                    "{$date}: geplante Dauer über der Verfügbarkeit",
                );
            }
        }
    }

    // ── Lieber wenige Einheiten mit Substanz ─────────────────────────────

    /**
     * Der Kern: reicht der Umfang nicht für alle Einheiten, fallen welche
     * weg. Vorher wurden aus 17 Restkilometern vier 24-Minuten-Läufe — vier
     * Einheiten ohne Trainingsreiz statt zwei mit.
     */
    public function test_a_tight_budget_drops_sessions_instead_of_shrinking_all(): void
    {
        $tight = $this->runSlots($this->build(25.0));
        $roomy = $this->runSlots($this->build(70.0));

        $this->assertLessThan(count($roomy), count($tight), 'Ein knapper Deckel muss Einheiten streichen');

        foreach ($tight as $slot) {
            $this->assertGreaterThanOrEqual(
                WeeklyPatternService::MIN_USEFUL_RUN_MINUTES,
                $slot['target_min'],
                'Was übrig bleibt, muss Substanz haben',
            );
        }
    }

    /** Eine Woche behält mindestens einen Lauf, auch bei winzigem Deckel. */
    public function test_even_a_tiny_budget_keeps_one_run(): void
    {
        $this->assertGreaterThanOrEqual(1, count($this->runSlots($this->build(6.0))));
    }

    /** Ein Tag, der seine Einheit ans Budget verliert, wird Ruhetag — kein offener Tag. */
    public function test_a_day_that_loses_its_slot_becomes_rest(): void
    {
        $skeleton = $this->build(25.0);

        foreach ($skeleton['days'] as $date => $day) {
            if (! $day['available'] || ! empty($day['finalized'])) {
                continue;
            }

            $this->assertTrue(
                ! empty($day['rest']) || ! empty($day['slots']),
                "{$date} ist weder Ruhetag noch belegt — das entschiede wieder das Modell",
            );
        }
    }

    // ── Der lange Lauf geht vor ──────────────────────────────────────────

    public function test_the_long_run_keeps_its_ladder_distance(): void
    {
        $longRuns = [
            'weeks' => ['2026-W36' => ['km' => 22.0, 'min' => 121, 'mp_km' => 6.0, 'kind' => 'build']],
        ];

        $slots = collect($this->runSlots($this->build(35.2, $longRuns)))
            ->firstWhere('type', 'long_run');

        $this->assertNotNull($slots);
        $this->assertSame(22.0, $slots['target_km'], 'Die Leiter hat Vorrang vor dem Budget');
    }

    /** Und was er verbraucht, fehlt dem Rest der Woche. */
    public function test_the_long_run_eats_into_the_rest_of_the_week(): void
    {
        $small = ['weeks' => ['2026-W36' => ['km' => 12.0, 'min' => 66, 'mp_km' => 0.0, 'kind' => 'build']]];
        $big   = ['weeks' => ['2026-W36' => ['km' => 26.0, 'min' => 143, 'mp_km' => 8.0, 'kind' => 'peak']]];

        $withSmall = collect($this->runSlots($this->build(35.2, $small)))->where('type', '!=', 'long_run');
        $withBig   = collect($this->runSlots($this->build(35.2, $big)))->where('type', '!=', 'long_run');

        $this->assertGreaterThan(
            $withBig->sum('target_km'),
            $withSmall->sum('target_km'),
            'Ein längerer Longrun muss dem Rest der Woche Umfang wegnehmen',
        );
    }

    // ── Ohne Daten bleibt alles beim Alten ───────────────────────────────

    public function test_without_volume_data_nothing_is_budgeted(): void
    {
        $from = CarbonImmutable::parse(self::TODAY);

        $skeleton = app(WeeklyPatternService::class)->build(
            $this->event(), $from, $from->addDays(6), $this->availability(),
        );

        foreach ($this->runSlots($skeleton) as $slot) {
            if ($slot['type'] !== 'long_run') {
                $this->assertArrayNotHasKey('target_km', $slot);
            }
        }
    }

    // ── Der Validator setzt die Zielumfänge durch ────────────────────────

    /**
     * Ein Hinweis im Prompt ist keine Durchsetzung. Plant das Modell die
     * volle Verfügbarkeit statt des Zielumfangs, wird korrigiert.
     */
    public function test_a_session_that_ignores_its_target_is_pulled_back(): void
    {
        $skeleton = $this->build(35.2);

        $date = collect($skeleton['days'])
            ->filter(fn ($d) => collect($d['slots'] ?? [])->contains(
                fn ($s) => $s['type'] !== 'long_run'
                    && isset($s['target_min'])
                    && empty($s['fixed'])
                    && in_array($s['type'], WeeklyPatternService::RUN_SLOT_TYPES, true),
            ))
            ->keys()
            ->first();

        $this->assertNotNull($date, 'Kein Laufslot mit Zielumfang im Geruest');

        $slot = collect($skeleton['days'][$date]['slots'])
            ->first(fn ($s) => in_array($s['type'], WeeklyPatternService::RUN_SLOT_TYPES, true) && empty($s['fixed']));

        // Das Modell schoepft die ganze Verfuegbarkeit aus.
        $answer = [[
            'date' => $date, 'type' => $slot['type'], 'title' => 'Zu lang',
            'description' => '', 'distance_km' => 22, 'duration_min' => $skeleton['days'][$date]['budget_min'],
            'pace_target' => '5:30', 'zone' => 2, 'intensity' => 'low',
        ]];

        $checked = app(\App\Services\TrainingPlanValidator::class)->validate(
            $answer, $skeleton, CarbonImmutable::parse(self::TODAY)->addDays(38)->toDateString(),
        );

        $onDate = collect($checked['sessions'])->firstWhere('date', $date);

        $this->assertSame($slot['target_min'], $onDate['duration_min']);
        $this->assertNotEmpty($checked['report']);
    }

    /** Innerhalb der Toleranz bleibt die Antwort des Modells stehen. */
    public function test_a_session_close_to_its_target_is_left_alone(): void
    {
        $skeleton = $this->build(35.2);

        $date = collect($skeleton['days'])
            ->filter(fn ($d) => collect($d['slots'] ?? [])->contains(
                fn ($s) => $s['type'] !== 'long_run' && isset($s['target_min']) && empty($s['fixed'])
                    && in_array($s['type'], WeeklyPatternService::RUN_SLOT_TYPES, true),
            ))
            ->keys()
            ->first();

        $slot    = collect($skeleton['days'][$date]['slots'])
            ->first(fn ($s) => in_array($s['type'], WeeklyPatternService::RUN_SLOT_TYPES, true) && empty($s['fixed']));
        $slightly = $slot['target_min'] + 5;

        $answer = [[
            'date' => $date, 'type' => $slot['type'], 'title' => 'Passt',
            'description' => '', 'distance_km' => $slot['target_km'], 'duration_min' => $slightly,
            'pace_target' => '5:30', 'zone' => 2, 'intensity' => 'low',
        ]];

        $checked = app(\App\Services\TrainingPlanValidator::class)->validate(
            $answer, $skeleton, CarbonImmutable::parse(self::TODAY)->addDays(38)->toDateString(),
        );

        $this->assertSame($slightly, collect($checked['sessions'])->firstWhere('date', $date)['duration_min']);
    }

    // ── Ein Urteil über das Ziel, nicht zwei ─────────────────────────────

    /**
     * Der Tempo-Vergleich sagte „Das Ziel passt zur heutigen Form", die
     * Leiter im selben Prompt „Mit dieser Vorbereitung ist die Zielzeit
     * unwahrscheinlich". Beides als Tatsache, ohne Rangfolge.
     */
    public function test_the_goal_verdict_accounts_for_the_long_run(): void
    {
        $paces = app(TrainingPaceService::class)->forEvent($this->event(), 4 + 22 / 60);

        $reachable   = ['reachable' => true,  'peak_km' => 32.0, 'ideal_peak_km' => 32.0];
        $unreachable = ['reachable' => false, 'peak_km' => 23.6, 'ideal_peak_km' => 32.0];

        $good = app(TrainingPaceService::class)->toPromptSection($paces, $reachable);
        $bad  = app(TrainingPaceService::class)->toPromptSection($paces, $unreachable);

        $this->assertStringContainsString('Das Ziel passt zur heutigen Form', $good);
        $this->assertStringNotContainsString('unwahrscheinlich', $good);

        $this->assertStringContainsString('Ausdauer noch nicht', $bad);
        $this->assertStringContainsString('23.6 km statt der nötigen 32 km', $bad);
        $this->assertStringNotContainsString('Das Ziel passt zur heutigen Form', $bad);
    }

    /** Ohne Leiter bleibt es beim reinen Tempo-Vergleich. */
    public function test_without_a_ladder_the_pace_verdict_stands(): void
    {
        $paces = app(TrainingPaceService::class)->forEvent($this->event(), 4 + 22 / 60);

        $this->assertStringContainsString(
            'Das Ziel passt zur heutigen Form',
            app(TrainingPaceService::class)->toPromptSection($paces),
        );
    }
}
