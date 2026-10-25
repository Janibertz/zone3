<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\TrainingPlanValidator;
use App\Services\WeeklyPatternService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Das norwegische Modell (Entscheidung vom 25.10.2026): zwei Tage
 * Doppel-Schwelle, eine kurze schnelle Einheit am Wochenende, ein lockerer
 * langer Lauf. Statt 80/20 höchstens 25 % Sub-Schwelle und 5 % schnell.
 *
 * Dasselbe Rennen wie in PeriodizationTest: Halbmarathon am 29.11.2026.
 * Die Woche ab 26.10. ist Peak und Belastung, 19.10. Entlastung, 17.08.
 * Grundlage, 23.11. Rennwoche.
 */
class NorwegianModelTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY    = '2026-08-31'; // ein Montag
    private const RACE_DAY = '2026-11-29';

    private const MODEL = ['name' => 'norwegian', 'threshold' => '4:10', 'sub_pace' => '4:15–4:25', 'hr_max' => 165];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY);
        CarbonImmutable::setTestNow(self::TODAY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function event(?User $user = null): Event
    {
        return Event::create([
            'user_id'             => ($user ?? User::factory()->create())->id,
            'name'                => 'Halbmarathon',
            'event_date'          => self::RACE_DAY,
            'race_distance'       => 'half_marathon',
            'priority'            => 'A',
            'target_time_hours'   => 1,
            'target_time_minutes' => 29,
        ]);
    }

    /** Jeder Tag 90 min, dazu Abendfenster an den genannten Tagen. */
    private function availability(array $evenings = ['tuesday' => 45, 'thursday' => 45]): array
    {
        return collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
            ->mapWithKeys(fn ($d) => [$d => ['available' => true, 'duration_min' => 90]
                + (isset($evenings[$d]) ? ['second_min' => $evenings[$d]] : [])])
            ->all();
    }

    private function week(
        string $monday,
        array $evenings = ['tuesday' => 45, 'thursday' => 45],
        ?float $weeklyMax = 60,
        ?array $longRuns = null,
        ?array $model = self::MODEL,
    ): array {
        $from = CarbonImmutable::parse($monday);

        return app(WeeklyPatternService::class)->build(
            $this->event(), $from, $from->addDays(6), $this->availability($evenings),
            [], [], null, $longRuns,
            $weeklyMax ? ['has_data' => true, 'next_week_max' => $weeklyMax] : null,
            $weeklyMax ? 330 : null,
            [],
            $model,
        );
    }

    private function slots(array $skeleton): array
    {
        return collect($skeleton['days'])
            ->flatMap(fn ($d, $date) => collect($d['slots'] ?? [])->map(fn ($s) => $s + ['date' => $date]))
            ->filter(fn ($s) => in_array($s['type'], WeeklyPatternService::RUN_SLOT_TYPES, true))
            ->values()
            ->all();
    }

    // ── Das Gerüst ───────────────────────────────────────────────────────

    public function test_a_loading_week_has_two_double_threshold_days(): void
    {
        $skeleton = $this->week('2026-10-26');
        $slots    = collect($this->slots($skeleton));

        $doubles = $slots->filter(fn ($s) => ! empty($s['double']))->groupBy('date');

        $this->assertSame(['2026-10-27', '2026-10-29'], $doubles->keys()->sort()->values()->all(), 'Doppel-Schwelle an den Tagen mit Abendfenster');
        foreach ($doubles as $date => $halves) {
            $this->assertSame(['am', 'pm'], $halves->pluck('double')->all(), $date);
            $this->assertSame(['tempo_run', 'tempo_run'], $halves->pluck('type')->all());
            $this->assertTrue($halves->every(fn ($s) => ! empty($s['controlled'])));
        }

        // Der Abend hat das Abendfenster als Obergrenze, nicht den Tag.
        $this->assertSame(45, $doubles['2026-10-27'][1]['max_min']);
    }

    public function test_the_fast_session_lands_on_the_weekend(): void
    {
        $fast = collect($this->slots($this->week('2026-10-26')))->firstWhere('fast', true);

        $this->assertNotNull($fast);
        $this->assertSame('interval', $fast['type']);
        $this->assertGreaterThanOrEqual(6, CarbonImmutable::parse($fast['date'])->isoWeekday());
    }

    public function test_the_long_run_stays_easy(): void
    {
        $longRuns = ['weeks' => ['2026-W44' => ['km' => 20.0, 'min' => 110, 'kind' => 'build', 'mp_km' => 8.0]]];
        $long     = collect($this->slots($this->week('2026-10-26', longRuns: $longRuns)))->firstWhere('type', 'long_run');

        $this->assertGreaterThan(0, $long['target_km']);
        $this->assertEquals(0, $long['race_km'], 'Kein Renntempo-Abschnitt im norwegischen Modell');
    }

    /** Sub-Schwelle verlangt keinen Ruhetag danach — der Tag nach der Doppel-Schwelle ist locker. */
    public function test_the_day_after_a_double_is_an_easy_run_not_rest(): void
    {
        $skeleton = $this->week('2026-10-26', weeklyMax: null);

        $wednesday = $skeleton['days']['2026-10-28'];
        $this->assertEmpty($wednesday['rest'] ?? null);
        $this->assertSame('easy_run', $wednesday['slots'][0]['type'] ?? null);

        // Ein Ruhetag pro Woche bleibt trotzdem.
        $this->assertTrue(collect($skeleton['days'])->contains(fn ($d) => ! empty($d['rest'])));
    }

    public function test_without_an_evening_window_the_threshold_stays_single(): void
    {
        $slots = collect($this->slots($this->week('2026-10-26', evenings: [])));

        $this->assertFalse($slots->contains(fn ($s) => ! empty($s['double'])));
        $this->assertSame(2, $slots->where('controlled', true)->count(), 'Zwei Einzel-Schwellen statt zwei Doppel-Tagen');
        $this->assertSame(7, $slots->pluck('date')->unique()->count() + collect($this->week('2026-10-26', evenings: [])['days'])->filter(fn ($d) => ! empty($d['rest']))->count());
    }

    public function test_sub_threshold_and_fast_minutes_stay_within_their_shares(): void
    {
        $slots = collect($this->slots($this->week('2026-10-26')));

        $total = $slots->sum('target_min');
        $sub   = $slots->where('controlled', true)->sum('hard_min');
        $fast  = $slots->where('fast', true)->sum('hard_min');

        $this->assertGreaterThan(0, $sub);
        $this->assertGreaterThan(0, $fast);
        $this->assertLessThanOrEqual($total * WeeklyPatternService::SUB_THRESHOLD_SHARE + 1, $sub);
        $this->assertLessThanOrEqual($total * WeeklyPatternService::FAST_SHARE + 1, $fast);
    }

    /** Eine knappe Woche verliert zuerst die Abend-Hälften, nicht die Schwelle als Ganzes. */
    public function test_a_tight_week_drops_the_evening_halves_first(): void
    {
        $slots = collect($this->slots($this->week('2026-10-26', weeklyMax: 30)));

        $this->assertFalse($slots->contains(fn ($s) => ($s['double'] ?? null) === 'pm'));
        $this->assertGreaterThanOrEqual(1, $slots->where('controlled', true)->count());
    }

    public function test_the_base_phase_has_one_threshold_day_and_no_fast_session(): void
    {
        $slots = collect($this->slots($this->week('2026-08-17')));

        $this->assertSame(1, $slots->filter(fn ($s) => ! empty($s['controlled']))->pluck('date')->unique()->count());
        $this->assertFalse($slots->contains(fn ($s) => ! empty($s['fast'])));
    }

    public function test_a_recovery_week_has_one_threshold_day_and_no_fast_session(): void
    {
        $slots = collect($this->slots($this->week('2026-10-19')));

        $this->assertSame(1, $slots->filter(fn ($s) => ! empty($s['controlled']))->pluck('date')->unique()->count());
        $this->assertFalse($slots->contains(fn ($s) => ! empty($s['fast'])));
    }

    public function test_race_week_has_no_double_threshold(): void
    {
        $slots = collect($this->slots($this->week('2026-11-23')));

        $this->assertFalse($slots->contains(fn ($s) => ! empty($s['double'])));
        $this->assertLessThanOrEqual(1, $slots->where('controlled', true)->count());
    }

    /** Ohne Modell (oder polarisiert) bleibt alles, wie es war. */
    public function test_the_polarized_model_has_no_doubles(): void
    {
        $slots = collect($this->slots($this->week('2026-10-26', model: null)));

        $this->assertFalse($slots->contains(fn ($s) => ! empty($s['double']) || ! empty($s['controlled'])));
        $this->assertSame(1, $slots->groupBy('date')->max(fn ($d) => $d->count()));
    }

    public function test_the_prompt_explains_double_days_pace_and_heart_rate(): void
    {
        $text = app(WeeklyPatternService::class)->toPromptSection($this->week('2026-10-26'));

        $this->assertStringContainsString('DOPPEL-SCHWELLE MORGENS, part="am"', $text);
        $this->assertStringContainsString('DOPPEL-SCHWELLE ABENDS, part="pm"', $text);
        $this->assertStringContainsString('4:15–4:25', $text);
        $this->assertStringContainsString('165 bpm', $text);
        $this->assertStringContainsString('hoechstens 25 % der Laufminuten Sub-Schwelle', $text);
        $this->assertStringNotContainsString('80/20 NACH ZEIT', $text);
    }

    // ── Der Validator ────────────────────────────────────────────────────

    /** Eine Modellantwort, die dem Gerüst folgt. */
    private function answer(array $skeleton, ?callable $tweak = null): array
    {
        $sessions = [];
        foreach ($skeleton['days'] as $date => $day) {
            if (! $day['slots']) {
                $sessions[] = ['date' => $date, 'type' => 'rest', 'title' => 'Ruhetag', 'duration_min' => 0, 'distance_km' => 0];
                continue;
            }

            foreach ($day['slots'] as $slot) {
                $s = [
                    'date'         => $date,
                    'type'         => $slot['type'],
                    'title'        => $slot['type'],
                    'description'  => 'Hauptteil.',
                    'duration_min' => $slot['target_min'] ?? 45,
                    'distance_km'  => $slot['target_km'] ?? 8,
                    'hard_min'     => $slot['hard_min'] ?? 0,
                ];
                if (! empty($slot['double'])) {
                    $s['part'] = $slot['double'];
                }
                $sessions[] = $tweak ? $tweak($s, $slot) : $s;
            }
        }

        return $sessions;
    }

    public function test_both_halves_of_a_double_day_survive_validation(): void
    {
        $skeleton = $this->week('2026-10-26');
        $result   = app(TrainingPlanValidator::class)->validate($this->answer($skeleton), $skeleton);

        $tuesday = collect($result['sessions'])->where('date', '2026-10-27')->values();

        $this->assertSame(['tempo_run', 'tempo_run'], $tuesday->pluck('type')->all());
        $this->assertSame(['am', 'pm'], $tuesday->pluck('part')->all(), 'Morgens vor abends');
        $this->assertFalse(collect($result['report'])->contains(fn ($r) => str_contains($r, 'Laufeinheit') || str_contains($r, 'harte Einheit')), implode("\n", $result['report']));
    }

    /** Fehlt `part`, gilt die Reihenfolge der Antwort. */
    public function test_missing_parts_are_assigned_in_order(): void
    {
        $skeleton = $this->week('2026-10-26');
        $result   = app(TrainingPlanValidator::class)->validate(
            $this->answer($skeleton, fn ($s) => array_diff_key($s, ['part' => 1])),
            $skeleton,
        );

        $this->assertSame(['am', 'pm'], collect($result['sessions'])->where('date', '2026-10-29')->pluck('part')->values()->all());
    }

    public function test_a_missing_evening_half_is_restored(): void
    {
        $skeleton = $this->week('2026-10-26');
        $result   = app(TrainingPlanValidator::class)->validate(
            array_values(array_filter($this->answer($skeleton), fn ($s) => ! ($s['date'] === '2026-10-27' && ($s['part'] ?? null) === 'pm'))),
            $skeleton,
        );

        $tuesday = collect($result['sessions'])->where('date', '2026-10-27')->values();
        $this->assertSame(['am', 'pm'], $tuesday->pluck('part')->all());
        $this->assertSame('Abends: Sub-Schwelle', $tuesday[1]['title']);
    }

    public function test_a_third_run_on_a_double_day_is_removed(): void
    {
        $skeleton = $this->week('2026-10-26');
        $sessions = $this->answer($skeleton);
        $sessions[] = ['date' => '2026-10-27', 'type' => 'easy_run', 'title' => 'Extra', 'duration_min' => 30, 'distance_km' => 5];

        $result = app(TrainingPlanValidator::class)->validate($sessions, $skeleton);

        $this->assertSame(2, collect($result['sessions'])->where('date', '2026-10-27')->count());
        $this->assertTrue(collect($result['report'])->contains(fn ($r) => str_contains($r, 'dritte Laufeinheit')));
    }

    /** Auf einem gewöhnlichen Tag gilt weiter: ein Lauf. */
    public function test_an_ordinary_day_still_allows_one_run(): void
    {
        $skeleton = $this->week('2026-10-26');
        $sessions = $this->answer($skeleton);
        $sessions[] = ['date' => '2026-10-28', 'type' => 'easy_run', 'title' => 'Extra', 'duration_min' => 30, 'distance_km' => 5];

        $result = app(TrainingPlanValidator::class)->validate($sessions, $skeleton);

        $runs = collect($result['sessions'])->where('date', '2026-10-28')
            ->filter(fn ($s) => in_array($s['type'], TrainingPlanValidator::RUN_TYPES, true));
        $this->assertSame(1, $runs->count());
    }

    public function test_a_sub_threshold_main_set_above_the_skeleton_is_cut_back(): void
    {
        $skeleton = $this->week('2026-10-26');
        $result   = app(TrainingPlanValidator::class)->validate(
            $this->answer($skeleton, fn ($s, $slot) => ($s['part'] ?? null) === 'am' ? [...$s, 'hard_min' => $slot['hard_min'] + 20] : $s),
            $skeleton,
        );

        $am = collect($result['sessions'])->where('date', '2026-10-27')->firstWhere('part', 'am');
        $this->assertStringContainsString('(Schwellenanteil 25 %)', $am['description']);
        $this->assertFalse(collect($result['report'])->contains(fn ($r) => str_contains($r, '80/20')));
    }

    /** Ist der Morgen erledigt, zeigt das Dashboard den Abend. */
    public function test_the_dashboard_shows_the_evening_half_once_the_morning_is_done(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user);
        $plan  = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'is_active' => true, 'sessions' => []]);

        foreach ([['Morgens: Sub-Schwelle', 'completed', 0], ['Abends: Sub-Schwelle', 'planned', 1]] as [$title, $status, $order]) {
            TrainingSession::create([
                'user_id' => $user->id, 'training_plan_id' => $plan->id, 'event_id' => $event->id,
                'planned_date' => self::TODAY, 'type' => 'tempo_run', 'title' => $title,
                'status' => $status, 'sort_order' => $order, 'intensity' => 'medium',
            ]);
        }

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('todayPlanSession.title', 'Abends: Sub-Schwelle'));
    }

    // ── Der Wechsel ──────────────────────────────────────────────────────

    public function test_with_an_active_plan_a_switch_waits_for_next_monday(): void
    {
        Carbon::setTestNow('2026-09-02 12:00'); // Mittwoch
        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user);
        TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'is_active' => true, 'sessions' => []]);

        $this->actingAs($user)->postJson(route('onboarding.training-model'), [
            'training_model' => 'norwegian', 'has_lactate_meter' => false,
        ])->assertOk()->assertJson(['next' => 'norwegian', 'next_from' => '2026-09-07']);

        $profile = $user->refresh()->runnerProfile;
        $this->assertSame('polarized', $profile->trainingModelOn('2026-09-06'));
        $this->assertSame('norwegian', $profile->trainingModelOn('2026-09-07'));
    }

    public function test_without_a_plan_the_model_applies_at_once(): void
    {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)->postJson(route('onboarding.training-model'), [
            'training_model' => 'norwegian', 'has_lactate_meter' => false,
        ])->assertOk()->assertJson(['next' => null]);

        $this->assertSame('norwegian', $user->refresh()->runnerProfile->trainingModelOn());
    }

    public function test_switching_back_cancels_the_pending_change(): void
    {
        $user  = User::factory()->onboarded()->create();
        $event = $this->event($user);
        TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'is_active' => true, 'sessions' => []]);

        foreach (['norwegian', 'polarized'] as $model) {
            // Frische Instanz je Anfrage, wie im echten Betrieb — sonst hängt
            // die zwischengespeicherte (leere) Profil-Relation am Testobjekt.
            $this->actingAs($user->fresh())->postJson(route('onboarding.training-model'), [
                'training_model' => $model, 'has_lactate_meter' => false,
            ])->assertOk();
        }

        $profile = $user->refresh()->runnerProfile;
        $this->assertNull($profile->training_model_next);
        $this->assertSame('polarized', $profile->trainingModelOn(now()->addWeeks(2)));
    }
}
