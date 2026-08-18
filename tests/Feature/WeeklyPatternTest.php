<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Services\TrainingPlanValidator;
use App\Services\WeeklyPatternService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Das Wochengerüst und der Validator sind der Ersatz für Prompt-Regeln, die
 * niemand durchgesetzt hat. Diese Tests halten fest, was jetzt garantiert
 * ist — unabhängig davon, was das Sprachmodell zurückgibt.
 */
class WeeklyPatternTest extends TestCase
{
    use RefreshDatabase;

    private function event(string $distance = 'half_marathon', int $daysUntil = 60): Event
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        return Event::create([
            'user_id'             => $user->id,
            'name'                => 'Testrennen',
            'event_date'          => now()->addDays($daysUntil),
            'race_distance'       => $distance,
            'priority'            => 'A',
            'target_time_hours'   => 1,
            'target_time_minutes' => 45,
        ]);
    }

    /** Jeder Tag verfügbar, mit dem angegebenen Zeitbudget. */
    private function availability(int $minutes = 90, array $except = []): array
    {
        $days = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $days[$day] = in_array($day, $except, true)
                ? ['available' => false, 'duration_min' => 0]
                : ['available' => true,  'duration_min' => $minutes];
        }

        return $days;
    }

    private function build(Event $event, ?array $availability, int $days = 14): array
    {
        $from = CarbonImmutable::parse('2026-08-17'); // ein Montag
        return app(WeeklyPatternService::class)->build(
            $event, $from, $from->addDays($days - 1), $availability
        );
    }

    // ── Gerüst ───────────────────────────────────────────────────────────

    public function test_every_week_contains_easy_tempo_and_interval(): void
    {
        $skeleton = $this->build($this->event(), $this->availability());

        $this->assertNotEmpty($skeleton['weeks']);

        foreach ($skeleton['weeks'] as $week => $data) {
            $this->assertContains('easy_run',  $data['planned'], "Woche {$week} ohne Easy Run");
            $this->assertContains('tempo_run', $data['planned'], "Woche {$week} ohne Tempolauf");
            $this->assertContains('interval',  $data['planned'], "Woche {$week} ohne Intervall");
        }
    }

    /** Bei knapper Woche entscheidet die Zielreihenfolge, was überlebt. */
    public function test_priority_depends_on_the_goal(): void
    {
        $onlyTwoDays = $this->availability(90, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']);

        $fiveK    = $this->build($this->event('5km'), $onlyTwoDays);
        $marathon = $this->build($this->event('marathon'), $onlyTwoDays);

        $firstWeek = fn ($s) => reset($s['weeks'])['planned'];

        // 5 km lebt von Schärfe, der Marathon vom langen Lauf.
        $this->assertSame('interval', $firstWeek($fiveK)[0]);
        $this->assertSame('long_run', $firstWeek($marathon)[0]);
    }

    /** Beim Backyard bleibt es bei einer harten Einheit pro Woche. */
    public function test_backyard_gets_only_one_hard_session_per_week(): void
    {
        $skeleton = $this->build($this->event('backyard_ultra'), $this->availability(120));

        foreach ($skeleton['weeks'] as $week => $data) {
            $hard = array_intersect($data['planned'], WeeklyPatternService::HARD_TYPES);
            $this->assertLessThanOrEqual(1, count($hard), "Woche {$week} hat mehr als eine harte Einheit");
        }
    }

    public function test_hard_sessions_keep_their_distance(): void
    {
        $skeleton = $this->build($this->event(), $this->availability());

        $hardDates = [];
        foreach ($skeleton['days'] as $date => $day) {
            foreach ($day['slots'] as $slot) {
                if ($slot['hard']) $hardDates[] = $date;
            }
        }

        foreach ($hardDates as $a) {
            foreach ($hardDates as $b) {
                if ($a >= $b) continue;
                $gap = CarbonImmutable::parse($a)->diffInDays(CarbonImmutable::parse($b));
                $this->assertGreaterThanOrEqual(2, $gap, "Harte Einheiten am {$a} und {$b} liegen zu eng");
            }
        }
    }

    public function test_unavailable_days_never_get_a_slot(): void
    {
        $skeleton = $this->build($this->event(), $this->availability(90, ['sunday', 'wednesday']));

        foreach ($skeleton['days'] as $date => $day) {
            if (! $day['available']) {
                $this->assertEmpty($day['slots'], "{$date} ist nicht verfügbar, hat aber eine Einheit");
            }
        }
    }

    /** Eine volle Woche behält mindestens einen Ruhetag. */
    public function test_a_full_week_keeps_a_rest_day(): void
    {
        $skeleton = $this->build($this->event(), $this->availability());

        $occupied = collect($skeleton['days'])
            ->filter(fn ($d) => $d['week'] === '2026-W34' && $d['slots'])
            ->count();

        $this->assertLessThanOrEqual(6, $occupied);
    }

    /** Bleibt nach der Pflichteinheit Zeit, darf eine zweite dazu. */
    public function test_long_days_get_a_second_slot(): void
    {
        $skeleton = $this->build($this->event(), $this->availability(150));

        $doubles = collect($skeleton['days'])->filter(fn ($d) => count($d['slots']) > 1);

        $this->assertNotEmpty($doubles, 'Bei 150 min pro Tag sollte es Doppel-Tage geben');
        foreach ($doubles as $day) {
            $hard = collect($day['slots'])->where('hard', true)->count();
            $this->assertLessThanOrEqual(1, $hard);
        }
    }

    /**
     * Ohne gepflegtes Wochenraster darf das Geruest nicht leer bleiben.
     *
     * Sonst haelt der Validator jeden Tag fuer gesperrt und macht aus dem
     * gesamten Plan Ruhetage — der Athlet bekaeme zwei Wochen Nichtstun,
     * nur weil sein Profil unvollstaendig ist.
     */
    public function test_a_profile_without_availability_still_gets_a_pattern(): void
    {
        $skeleton = $this->build($this->event(), null);

        $usable = collect($skeleton['days'])->where('available', true)->count();
        $this->assertSame(14, $usable, 'Ohne Angabe muss jeder Tag nutzbar sein');

        foreach ($skeleton['weeks'] as $week => $data) {
            $this->assertContains('easy_run',  $data['planned'], "Woche {$week} ohne Easy Run");
            $this->assertContains('tempo_run', $data['planned'], "Woche {$week} ohne Tempolauf");
            $this->assertContains('interval',  $data['planned'], "Woche {$week} ohne Intervall");
        }
    }

    /** Und der Validator darf daraus keine Ruhetage machen. */
    public function test_a_profile_without_availability_keeps_its_sessions(): void
    {
        $skeleton = $this->build($this->event(), null);
        $date     = array_key_first($skeleton['days']);

        $result = $this->validate([
            ['date' => $date, 'type' => 'interval', 'title' => 'Intervalle', 'duration_min' => 60],
        ], $skeleton);

        $entry = collect($result['sessions'])->firstWhere('date', $date);
        $this->assertSame('interval', $entry['type']);
    }

    /** Verfuegbar ohne Zeitangabe heisst „keine Obergrenze", nicht „gesperrt". */
    public function test_an_available_day_without_a_duration_stays_usable(): void
    {
        $availability = $this->availability(90);
        $availability['wednesday'] = ['available' => true, 'duration_min' => 0];

        $skeleton = $this->build($this->event(), $availability);
        $wednesday = collect($skeleton['days'])->firstWhere('weekday', 3);

        $this->assertTrue($wednesday['available']);
    }

    // ── Feste Wochentermine ──────────────────────────────────────────────

    /**
     * Der Laufclub laeuft jede Woche am selben Tag, mit wechselndem Inhalt.
     * Ihn als Ruhetag einzutragen war der bisherige Behelf — dann legte das
     * Geruest das Wochenintervall zusaetzlich auf einen anderen Tag, und der
     * Athlet lief zwei harte Einheiten pro Woche.
     */
    public function test_a_fixed_appointment_replaces_the_planned_session_of_its_type(): void
    {
        $availability = $this->availability(90);
        $availability['tuesday']['fixed'] = ['type' => 'interval', 'label' => 'Laufclub'];

        $skeleton = $this->build($this->event('10km'), $availability);

        foreach ($skeleton['days'] as $date => $day) {
            $intervals = collect($day['slots'])->where('type', 'interval');
            if ($day['weekday'] === 2) {
                $this->assertCount(1, $intervals, "Am Dienstag ({$date}) fehlt der feste Termin");
                $this->assertTrue($intervals->first()['fixed']);
                $this->assertSame('Laufclub', $intervals->first()['label']);
            } else {
                $this->assertCount(0, $intervals, "Am {$date} steht ein zweites Intervall");
            }
        }
    }

    /** Genau eine harte Einheit pro Woche zusaetzlich zum festen Termin. */
    public function test_a_fixed_hard_session_counts_towards_the_weekly_budget(): void
    {
        $availability = $this->availability(90);
        $availability['tuesday']['fixed'] = ['type' => 'interval', 'label' => 'Laufclub'];

        $skeleton = $this->build($this->event('10km'), $availability);

        foreach ($skeleton['weeks'] as $week => $data) {
            $hard = array_intersect($data['planned'], WeeklyPatternService::HARD_TYPES);
            $this->assertLessThanOrEqual(2, count($hard), "Woche {$week} hat zu viele harte Einheiten");
            $this->assertContains('interval', $data['planned']);
        }
    }

    /** An einem gesperrten Tag gibt es keinen festen Termin. */
    public function test_a_fixed_appointment_on_a_blocked_day_is_ignored(): void
    {
        $availability = $this->availability(90, ['tuesday']);
        $availability['tuesday']['fixed'] = ['type' => 'interval', 'label' => 'Laufclub'];

        $skeleton = $this->build($this->event(), $availability);

        $tuesday = collect($skeleton['days'])->firstWhere('weekday', 2);
        $this->assertNull($tuesday['fixed']);
        $this->assertEmpty($tuesday['slots']);
    }

    /** Der Prompt sagt ausdruecklich, dass der Inhalt nicht erfunden werden darf. */
    public function test_the_prompt_marks_fixed_appointments(): void
    {
        $availability = $this->availability(90);
        $availability['tuesday']['fixed'] = ['type' => 'interval', 'label' => 'Laufclub'];

        $text = app(WeeklyPatternService::class)
            ->toPromptSection($this->build($this->event(), $availability));

        $this->assertStringContainsString('FESTER TERMIN: Laufclub', $text);
        $this->assertStringContainsString('wechselt wöchentlich', $text);
    }

    /** Der Validator erfindet fuer einen festen Termin kein Workout. */
    public function test_a_missing_fixed_appointment_is_restored_without_invented_content(): void
    {
        $availability = $this->availability(90);
        $availability['tuesday']['fixed'] = ['type' => 'interval', 'label' => 'Laufclub'];

        $skeleton = $this->build($this->event(), $availability);
        $tuesday  = collect($skeleton['days'])->firstWhere('weekday', 2)['date'];

        // Das Modell liefert an dem Tag einen Ruhetag.
        $result = $this->validate([
            ['date' => $tuesday, 'type' => 'rest', 'title' => 'Ruhetag', 'duration_min' => 0],
        ], $skeleton);

        $entry = collect($result['sessions'])->firstWhere('date', $tuesday);
        $this->assertSame('interval', $entry['type']);
        $this->assertSame('Laufclub', $entry['title']);
        $this->assertNull($entry['pace_target']);
    }

    /** Kurze Tage bleiben einfach belegt. */
    public function test_short_days_stay_single(): void
    {
        $skeleton = $this->build($this->event(), $this->availability(45));

        foreach ($skeleton['days'] as $date => $day) {
            $this->assertLessThanOrEqual(1, count($day['slots']), "{$date} hat zwei Einheiten bei nur 45 min");
        }
    }

    // ── Validator ────────────────────────────────────────────────────────

    private function validate(array $sessions, array $skeleton): array
    {
        return app(TrainingPlanValidator::class)->validate($sessions, $skeleton);
    }

    public function test_a_session_on_an_unavailable_day_becomes_rest(): void
    {
        $skeleton = $this->build($this->event(), $this->availability(90, ['sunday']));
        $sunday   = collect($skeleton['days'])->firstWhere('weekday', 7)['date'];

        $result = $this->validate([
            ['date' => $sunday, 'type' => 'interval', 'title' => 'Trotzdem', 'duration_min' => 60],
        ], $skeleton);

        $entry = collect($result['sessions'])->firstWhere('date', $sunday);
        $this->assertSame('rest', $entry['type']);
        $this->assertNotEmpty($result['report']);
    }

    public function test_an_invented_type_is_replaced(): void
    {
        $skeleton = $this->build($this->event(), $this->availability());
        $date     = array_key_first($skeleton['days']);

        $result = $this->validate([
            ['date' => $date, 'type' => 'recovery_jog', 'title' => 'Erfunden', 'duration_min' => 30],
        ], $skeleton);

        $entry = collect($result['sessions'])->firstWhere('date', $date);
        $this->assertContains($entry['type'], TrainingPlanValidator::KNOWN_TYPES);
    }

    public function test_a_day_over_its_budget_is_shortened(): void
    {
        $skeleton = $this->build($this->event(), $this->availability(60));
        $date     = collect($skeleton['days'])->firstWhere('slots', '!=', [])['date'];

        $result = $this->validate([
            ['date' => $date, 'type' => 'easy_run', 'title' => 'Zu lang', 'duration_min' => 180, 'distance_km' => 30],
        ], $skeleton);

        $entry = collect($result['sessions'])->firstWhere('date', $date);
        $this->assertLessThanOrEqual(60, $entry['duration_min']);
        $this->assertLessThan(30, $entry['distance_km']);
    }

    /** Der eigentliche Zweck: das Muster gilt auch, wenn das Modell es ignoriert. */
    public function test_a_missing_interval_is_restored(): void
    {
        $skeleton = $this->build($this->event(), $this->availability());

        $intervalDate = null;
        foreach ($skeleton['days'] as $date => $day) {
            foreach ($day['slots'] as $slot) {
                if ($slot['type'] === 'interval') { $intervalDate = $date; break 2; }
            }
        }
        $this->assertNotNull($intervalDate);

        // Das Modell liefert an dem Tag einen Ruhetag statt des Intervalls.
        $result = $this->validate([
            ['date' => $intervalDate, 'type' => 'rest', 'title' => 'Ruhetag', 'duration_min' => 0],
        ], $skeleton);

        $entry = collect($result['sessions'])->firstWhere('date', $intervalDate);
        $this->assertSame('interval', $entry['type']);
    }

    public function test_two_hard_sessions_on_one_day_get_downgraded(): void
    {
        $skeleton = $this->build($this->event(), $this->availability(180));
        $date     = array_key_first($skeleton['days']);

        $result = $this->validate([
            ['date' => $date, 'type' => 'interval',  'title' => 'A', 'duration_min' => 60],
            ['date' => $date, 'type' => 'tempo_run', 'title' => 'B', 'duration_min' => 60],
        ], $skeleton);

        $onDate = collect($result['sessions'])->where('date', $date);
        $hard   = $onDate->filter(fn ($s) => in_array($s['type'], WeeklyPatternService::HARD_TYPES, true));

        $this->assertCount(1, $hard);
    }

    public function test_days_outside_the_window_are_dropped(): void
    {
        $skeleton = $this->build($this->event(), $this->availability());

        $result = $this->validate([
            ['date' => '2030-01-01', 'type' => 'easy_run', 'title' => 'Weit weg', 'duration_min' => 40],
        ], $skeleton);

        $this->assertEmpty(collect($result['sessions'])->where('date', '2030-01-01'));
    }

    /** Kein Tag im Fenster darf leer bleiben, sonst klafft die Liste. */
    public function test_every_day_in_the_window_gets_an_entry(): void
    {
        $skeleton = $this->build($this->event(), $this->availability());

        $result = $this->validate([], $skeleton);

        $dates = collect($result['sessions'])->pluck('date')->unique();
        $this->assertCount(count($skeleton['days']), $dates);
    }
}
