<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Event;
use App\Models\User;
use App\Models\WellbeingEntry;
use App\Services\PlanContextBuilder;
use App\Services\ReturnToRunService;
use App\Services\TrainingPlanValidator;
use App\Services\WeeklyPatternService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gemeldet: nach einer Krankheit standen an einem Tag ein Tempolauf über 60
 * Minuten UND ein „Re-Start nach Infekt" über 30 Minuten, tags darauf ein
 * Intervalltraining UND ein zweiter ruhiger Aufbau.
 *
 * Der Grund stand im Prompt selbst. Das Wochengerüst legte einen Tempolauf
 * auf den Tag und nannte ihn bindend; die Sicherheitsregel darüber verlangte
 * für dieselbe erste Einheit nach der Pause 30 lockere Minuten. Beide
 * Vorgaben waren als Pflicht formuliert, und das Modell erfüllte beide — mit
 * zwei Läufen an einem Tag. Geprüft wurde das nie: der Validator kannte nur
 * „zwei harte Einheiten", hart plus locker lief durch.
 *
 * Der Athlet will höchstens ein Lauftraining pro Tag; eine zweite Einheit
 * darf Kraft oder Mobility sein, wenn die Zeit reicht.
 */
class OneRunPerDayTest extends TestCase
{
    use RefreshDatabase;

    private function athlete(bool $sickToday = false): User
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        // Eine bestehende Laufroutine — sonst greift die Wiedereinstiegs-
        // Erkennung gar nicht.
        foreach ([12, 10, 8, 6, 4] as $i => $daysAgo) {
            Activity::create([
                'user_id'      => $user->id,
                'strava_id'    => 900000 + $i,
                'name'         => "Lauf -{$daysAgo}",
                'type'         => 'Run',
                'distance'     => 9000,
                'moving_time'  => 2700,
                'elapsed_time' => 2700,
                'start_date'   => now()->subDays($daysAgo),
            ]);
        }

        if ($sickToday) {
            WellbeingEntry::create([
                'user_id'         => $user->id,
                'date'            => now()->toDateString(),
                'energy_level'    => 4,
                'mood'            => 5,
                'sleep_quality'   => 6,
                'muscle_soreness' => 4,
                'stress_level'    => 4,
                'is_sick'         => true,
                'is_injured'      => false,
            ]);
        }

        return $user->refresh();
    }

    private function event(User $user, string $distance = 'marathon'): Event
    {
        return Event::create([
            'user_id'             => $user->id,
            'name'                => 'Berlin Marathon',
            'event_date'          => now()->addDays(38),
            'race_distance'       => $distance,
            'priority'            => 'A',
            'target_time_hours'   => 3,
            'target_time_minutes' => 30,
        ]);
    }

    /** Das Wochenraster des Meldenden: viel Zeit am Wochenende. */
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

    private function skeleton(User $user, ?array $comeback = null): array
    {
        $from = CarbonImmutable::today();

        return app(WeeklyPatternService::class)->build(
            $this->event($user),
            $from,
            $from->addDays(13),
            $this->availability(),
            [],
            [],
            $comeback,
        );
    }

    // ── Der Validator garantiert es, egal was das Modell liefert ─────────

    public function test_a_second_run_on_the_same_day_is_dropped(): void
    {
        $user     = $this->athlete();
        $skeleton = $this->skeleton($user);
        $date     = collect($skeleton['days'])
            ->first(fn ($d) => collect($d['slots'])->contains(fn ($s) => $s['type'] === 'tempo_run'))['date'];

        $result = app(TrainingPlanValidator::class)->validate([
            ['date' => $date, 'type' => 'tempo_run', 'title' => 'Tempolauf',   'duration_min' => 60],
            ['date' => $date, 'type' => 'easy_run',  'title' => 'Re-Start',    'duration_min' => 30],
        ], $skeleton);

        $onThatDay = collect($result['sessions'])->where('date', $date);
        $runs      = $onThatDay->whereIn('type', TrainingPlanValidator::RUN_TYPES);

        $this->assertCount(1, $runs, 'Ein Tag darf nur eine Laufeinheit haben');
        $this->assertSame('tempo_run', $runs->first()['type'], 'Bleiben muss die Einheit aus dem Gerüst');
    }

    /** Auch dann, wenn die zweite Einheit die aus dem Gerüst ist. */
    public function test_the_skeleton_session_wins_over_an_invented_one(): void
    {
        $user     = $this->athlete();
        $skeleton = $this->skeleton($user);
        $date     = collect($skeleton['days'])
            ->first(fn ($d) => collect($d['slots'])->contains(fn ($s) => $s['type'] === 'long_run'))['date'];

        $result = app(TrainingPlanValidator::class)->validate([
            ['date' => $date, 'type' => 'easy_run', 'title' => 'Locker',    'duration_min' => 40],
            ['date' => $date, 'type' => 'long_run', 'title' => 'Langer Lauf', 'duration_min' => 100],
        ], $skeleton);

        $runs = collect($result['sessions'])->where('date', $date)
            ->whereIn('type', TrainingPlanValidator::RUN_TYPES);

        $this->assertCount(1, $runs);
        $this->assertSame('long_run', $runs->first()['type']);
    }

    /** Kraft daneben bleibt erlaubt — das ist der Sinn der zweiten Einheit. */
    public function test_strength_may_stay_next_to_a_run(): void
    {
        $user     = $this->athlete();
        $skeleton = $this->skeleton($user);
        $date     = collect($skeleton['days'])
            ->first(fn ($d) => collect($d['slots'])->contains(fn ($s) => $s['type'] === 'long_run'))['date'];

        $result = app(TrainingPlanValidator::class)->validate([
            ['date' => $date, 'type' => 'long_run', 'title' => 'Langer Lauf', 'duration_min' => 100],
            ['date' => $date, 'type' => 'strength', 'title' => 'Kettlebell',  'duration_min' => 25],
        ], $skeleton);

        $types = collect($result['sessions'])->where('date', $date)->pluck('type')->all();

        $this->assertContains('long_run', $types);
        $this->assertContains('strength', $types);
    }

    /**
     * Fehlt die Einheit aus dem Gerüst und steht stattdessen ein anderer Lauf
     * da, wird sie ersetzt — früher wurde sie ergänzt, und der Tag hatte zwei.
     */
    public function test_a_missing_skeleton_session_replaces_the_wrong_run(): void
    {
        $user     = $this->athlete();
        $skeleton = $this->skeleton($user);
        $date     = collect($skeleton['days'])
            ->first(fn ($d) => collect($d['slots'])->contains(fn ($s) => $s['type'] === 'interval'))['date'];

        $result = app(TrainingPlanValidator::class)->validate([
            ['date' => $date, 'type' => 'easy_run', 'title' => 'Locker', 'duration_min' => 45],
        ], $skeleton);

        $runs = collect($result['sessions'])->where('date', $date)
            ->whereIn('type', TrainingPlanValidator::RUN_TYPES);

        $this->assertCount(1, $runs);
        $this->assertSame('interval', $runs->first()['type']);
    }

    // ── Das Gerüst kennt den Wiedereinstieg ──────────────────────────────

    public function test_illness_today_puts_the_athlete_on_step_one(): void
    {
        $user     = $this->athlete(sickToday: true);
        $comeback = app(ReturnToRunService::class)->forPlan(
            $user,
            [['date' => now()->toDateString(), 'energy' => 4, 'sleep' => 6, 'soreness' => 4, 'stress' => 4, 'is_sick' => true, 'is_injured' => false]],
            [],
        );

        $this->assertNotNull($comeback);
        $this->assertSame('sick', $comeback['trigger']);
        $this->assertSame(1, $comeback['step']);
        $this->assertSame(30, $comeback['max_min']);
    }

    /**
     * Der Kern des gemeldeten Fehlers: solange die Leiter läuft, darf im
     * Gerüst keine harte Einheit stehen. Sonst widerspricht das Gerüst der
     * Sicherheitsregel — und das Modell legt beides an einen Tag.
     */
    public function test_a_comeback_skeleton_has_no_hard_session_and_one_run_per_day(): void
    {
        $user     = $this->athlete(sickToday: true);
        $comeback = ['step' => 1, 'total_steps' => 5, 'trigger' => 'sick', 'trigger_label' => 'Krankheit'];
        $skeleton = $this->skeleton($user, $comeback);

        $firstDays = collect($skeleton['days'])->take(4);

        foreach ($firstDays as $date => $day) {
            $runs = collect($day['slots'])->whereIn('type', TrainingPlanValidator::RUN_TYPES);
            $this->assertLessThanOrEqual(1, $runs->count(), "{$date} hat zwei Laufeinheiten");

            foreach ($day['slots'] as $slot) {
                $this->assertNotContains(
                    $slot['type'],
                    WeeklyPatternService::HARD_TYPES,
                    "{$date}: harte Einheit \"{$slot['type']}\" trotz Wiedereinstieg"
                );
            }
        }

        // Die erste Einheit trägt den Deckel der Stufe.
        $first = collect($skeleton['days'])->first(fn ($d) => $d['slots']);
        $this->assertSame(30, $first['slots'][0]['max_min']);
    }

    /** Und der Deckel der Einheit gilt auch gegen ein großzügiges Tagesbudget. */
    public function test_a_slot_cap_shortens_an_overlong_session(): void
    {
        $user     = $this->athlete(sickToday: true);
        $skeleton = $this->skeleton($user, ['step' => 1, 'total_steps' => 5, 'trigger' => 'sick', 'trigger_label' => 'Krankheit']);

        $first = collect($skeleton['days'])->first(fn ($d) => $d['slots']);

        $result = app(TrainingPlanValidator::class)->validate([
            ['date' => $first['date'], 'type' => $first['slots'][0]['type'], 'title' => 'Zu lang', 'duration_min' => 75, 'distance_km' => 15],
        ], $skeleton);

        $entry = collect($result['sessions'])->firstWhere('date', $first['date']);
        $this->assertSame(30, $entry['duration_min']);
    }

    /** Nach der Krankheit steht die Stufe auch im Kontext des Planers. */
    public function test_the_plan_context_carries_the_comeback(): void
    {
        $user  = $this->athlete(sickToday: true);
        $user->runnerProfile()->create([
            'threshold_speed'     => 4.4,
            'weekly_availability' => $this->availability(),
        ]);

        $context = app(PlanContextBuilder::class)->build($user->refresh(), $this->event($user));

        $this->assertNotNull($context->comeback);
        $this->assertSame(1, $context->comeback['step']);
        $this->assertSame($context->comeback, $context->skeleton['comeback']);
    }

    // ── Zwei Nebenbefunde aus demselben Gerüst ───────────────────────────

    /** Ein „Langer Lauf" gehört nicht in einen 45-Minuten-Tag. */
    public function test_a_long_run_needs_a_day_that_carries_it(): void
    {
        $user = $this->athlete();
        $tight = collect($this->availability())
            ->map(fn ($d) => ['available' => true, 'duration_min' => 45])
            ->all();

        $from     = CarbonImmutable::today();
        $skeleton = app(WeeklyPatternService::class)->build(
            $this->event($user), $from, $from->addDays(13), $tight
        );

        foreach ($skeleton['days'] as $date => $day) {
            foreach ($day['slots'] as $slot) {
                $this->assertNotSame('long_run', $slot['type'], "{$date}: Langer Lauf in 45 Minuten");
            }
        }
    }

    /** Die Ergänzung bleibt eine Ergänzung, auch wenn der Tag lang ist. */
    public function test_the_second_slot_stays_short(): void
    {
        $user = $this->athlete();
        $skeleton = $this->skeleton($user);

        foreach ($skeleton['days'] as $date => $day) {
            foreach ($day['slots'] as $slot) {
                if (empty($slot['optional'])) continue;
                $this->assertLessThanOrEqual(30, $slot['max_min'], "{$date}: Zweiteinheit über 30 Minuten");
            }
        }
    }
}
