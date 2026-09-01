<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Services\LongRunPlanService;
use App\Services\TrainingPlanValidator;
use App\Services\WeeklyPatternService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Der lange Lauf ist bei einer Marathonvorbereitung nicht eine Einheit unter
 * vielen, sondern das Rückgrat. Trotzdem entstand er bisher Woche für Woche
 * neu: das Gerüst legte einen Slot auf den längsten Tag, das Modell füllte
 * eine Distanz ein, und als einzige Bremse gab es „höchstens zwei Kilometer
 * mehr als letzte Woche".
 *
 * Das ist die falsche Frage. Nicht „wie viel mehr als letzte Woche", sondern
 * „wo muss der Athlet am Renntag stehen, und welche Läufe führen ihn
 * dorthin". Deshalb wird die Leiter vom Renntag rückwärts gerechnet.
 */
class LongRunLadderTest extends TestCase
{
    use RefreshDatabase;

    /** Renntag ist ein Sonntag — der Fall, an dem die Wochenrechnung scheiterte. */
    private const RACE_DAY = '2026-09-27';
    private const TODAY    = '2026-08-20';

    private function event(string $distance = 'marathon'): Event
    {
        $user = User::factory()->create();

        return Event::create([
            'user_id'             => $user->id,
            'name'                => 'Berlin Marathon',
            'event_date'          => self::RACE_DAY,
            'race_distance'       => $distance,
            'priority'            => 'A',
            'target_time_hours'   => 3,
            'target_time_minutes' => 30,
        ]);
    }

    private function ladder(float $longestRun, int $budgetMin = 180, string $distance = 'marathon'): ?array
    {
        return app(LongRunPlanService::class)->forEvent(
            $this->event($distance),
            ['longest_run' => $longestRun],
            330,
            $budgetMin,
            CarbonImmutable::parse(self::TODAY),
        );
    }

    // ── Die Leiter selbst ────────────────────────────────────────────────

    /**
     * Der längste Lauf gehört drei Wochen vor den Marathon. Über Wochen
     * gezählt rutschte er eine Woche zu früh, sobald das Rennen — wie fast
     * jeder Marathon — auf einen Sonntag fiel.
     */
    public function test_the_peak_sits_three_weeks_before_the_marathon(): void
    {
        $ladder = $this->ladder(28.0);

        $peakWeek = collect($ladder['weeks'])->search(fn ($w) => $w['kind'] === 'peak');

        // Renntag 27.09. liegt in KW 39, drei Wochen davor ist KW 36.
        $this->assertSame('2026-W36', $peakWeek);
    }

    /** Danach wird der lange Lauf kürzer — und hört nicht einfach auf. */
    public function test_the_taper_steps_down_from_the_peak(): void
    {
        $ladder = $this->ladder(28.0);
        $weeks  = $ladder['weeks'];

        $this->assertSame(32.0, $weeks['2026-W36']['km']);
        $this->assertSame(24.0, $weeks['2026-W37']['km']);
        $this->assertSame(16.0, $weeks['2026-W38']['km']);
    }

    /** Zwei gleich lange Läufe hintereinander sind kein Aufbau. */
    public function test_no_two_equal_long_runs_in_a_row(): void
    {
        $ladder = $this->ladder(28.0);
        $build  = collect($ladder['weeks'])->filter(fn ($w) => $w['kind'] !== 'taper')->values();

        for ($i = 1; $i < $build->count(); $i++) {
            $this->assertNotEquals(
                $build[$i - 1]['km'],
                $build[$i]['km'],
                'Zwei identische lange Läufe hintereinander'
            );
        }
    }

    /** Die Renntempo-Abschnitte liegen im Aufbau, nicht am Anfang der Leiter. */
    public function test_race_pace_segments_appear_in_the_long_runs(): void
    {
        $ladder = $this->ladder(28.0);

        $this->assertGreaterThan(0, $ladder['weeks']['2026-W36']['mp_km']);
        $this->assertLessThanOrEqual(12.0, $ladder['weeks']['2026-W36']['mp_km']);
    }

    // ── Wenn es nicht reicht ─────────────────────────────────────────────

    /**
     * Wer fünf Wochen vor dem Marathon bei 16 km steht, kommt nicht mehr auf
     * 32. Der Plan streckt das nicht heimlich, sondern sagt es.
     */
    public function test_an_unreachable_peak_is_stated_plainly(): void
    {
        $ladder = $this->ladder(16.0);

        $this->assertFalse($ladder['reachable']);
        $this->assertLessThan(32.0, $ladder['peak_km']);

        // Der Befund steht hier — das Urteil ueber die Zielzeit nicht.
        // Es faellt in der "Einordnung" der Tempo-Sektion, die Tempo UND
        // Ausdauer zusammen betrachtet; stand es an beiden Stellen,
        // widersprachen sie sich im selben Prompt.
        $this->assertStringContainsString('reicht der lange Lauf nur bis', $ladder['verdict']);
        $this->assertStringNotContainsString('Zielzeit unwahrscheinlich', $ladder['verdict']);
    }

    /**
     * Ein zu kurzer Tag ist etwas anderes als zu wenig Wochen — das eine
     * lässt sich trainieren, das andere nur umräumen.
     */
    public function test_a_short_day_is_named_as_a_calendar_problem(): void
    {
        $ladder = $this->ladder(16.0, budgetMin: 90);

        $this->assertStringContainsString('Kalenderproblem', $ladder['verdict']);
        $this->assertStringContainsString('90 Minuten', $ladder['verdict']);
        $this->assertLessThan(20.0, $ladder['peak_km']);
    }

    /** Kürzere Distanzen brauchen keinen 32er — und bekommen ihn auch nicht. */
    public function test_a_ten_k_gets_a_shorter_ladder(): void
    {
        $ladder = $this->ladder(14.0, distance: '10km');

        $this->assertLessThanOrEqual(16.0, $ladder['peak_km']);
        $this->assertSame(0.0, $ladder['weeks']['2026-W36']['mp_km'] ?? 0.0, '10 km braucht kein Renntempo im Longrun');
    }

    /**
     * Die Leiter rechnet mit dem laengsten Tag der WOCHE, das Geruest legt
     * den langen Lauf auf den laengsten FREIEN Tag. Ist der kuerzer, passte
     * die Zieldistanz nicht hinein — in Jans Logs vier Mal
     * "long_run mit 177 min, erlaubt sind 120 → gekuerzt".
     */
    public function test_the_long_run_fits_the_day_it_actually_lands_on(): void
    {
        $event  = $this->event();
        $ladder = $this->ladder(28.0);
        $from   = CarbonImmutable::parse(self::TODAY);

        // Der Sonntag mit den drei Stunden ist gesperrt — der lange Lauf
        // muss auf einen kuerzeren Tag ausweichen.
        $availability = collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
            ->mapWithKeys(fn ($d) => [$d => $d === 'sunday'
                ? ['available' => false, 'duration_min' => 0]
                : ['available' => true, 'duration_min' => $d === 'saturday' ? 120 : 60]])
            ->all();

        $skeleton = app(WeeklyPatternService::class)
            ->build($event, $from, $from->addDays(13), $availability, [], [], null, $ladder);

        foreach ($skeleton['days'] as $date => $day) {
            foreach ($day['slots'] ?? [] as $slot) {
                if ($slot['type'] !== 'long_run') {
                    continue;
                }

                $cap = (int) ($slot['max_min'] ?: $day['budget_min']);

                $this->assertLessThanOrEqual(
                    $cap,
                    $slot['target_min'],
                    "{$date}: langer Lauf laenger als der Tag hergibt — der Validator muesste jedes Mal kuerzen",
                );
            }
        }
    }

    // ── Durchsetzung ─────────────────────────────────────────────────────

    /** Das Gerüst trägt die Distanz an den Longrun-Slot. */
    public function test_the_skeleton_carries_the_target_distance(): void
    {
        $event  = $this->event();
        $ladder = $this->ladder(28.0);
        $from   = CarbonImmutable::parse(self::TODAY);

        $availability = collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
            ->mapWithKeys(fn ($d) => [$d => ['available' => true, 'duration_min' => $d === 'sunday' ? 180 : 60]])
            ->all();

        $skeleton = app(WeeklyPatternService::class)
            ->build($event, $from, $from->addDays(13), $availability, [], [], null, $ladder);

        $longRun = collect($skeleton['days'])
            ->flatMap(fn ($d) => $d['slots'])
            ->firstWhere('type', 'long_run');

        $this->assertNotNull($longRun);
        $this->assertArrayHasKey('target_km', $longRun);
        $this->assertGreaterThan(20, $longRun['target_km']);
    }

    /** Und der Validator holt eine abweichende Distanz zurück auf die Leiter. */
    public function test_the_validator_pulls_a_stray_long_run_back(): void
    {
        $event  = $this->event();
        $ladder = $this->ladder(28.0);
        $from   = CarbonImmutable::parse(self::TODAY);

        $availability = collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
            ->mapWithKeys(fn ($d) => [$d => ['available' => true, 'duration_min' => $d === 'sunday' ? 180 : 60]])
            ->all();

        $skeleton = app(WeeklyPatternService::class)
            ->build($event, $from, $from->addDays(13), $availability, [], [], null, $ladder);

        $date = collect($skeleton['days'])
            ->first(fn ($d) => collect($d['slots'])->contains(fn ($s) => $s['type'] === 'long_run'))['date'];
        $target = collect($skeleton['days'][$date]['slots'])->firstWhere('type', 'long_run')['target_km'];

        $result = app(TrainingPlanValidator::class)->validate([
            ['date' => $date, 'type' => 'long_run', 'title' => 'Zu kurz', 'distance_km' => 14, 'duration_min' => 80],
        ], $skeleton);

        $entry = collect($result['sessions'])->firstWhere('date', $date);
        $this->assertSame($target, $entry['distance_km']);
    }

    /** Eine gerundete Zahl bleibt aber stehen — 24 statt 24,4 ist kein Fehler. */
    public function test_a_rounded_distance_is_left_alone(): void
    {
        $event  = $this->event();
        $ladder = $this->ladder(28.0);
        $from   = CarbonImmutable::parse(self::TODAY);

        $availability = collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
            ->mapWithKeys(fn ($d) => [$d => ['available' => true, 'duration_min' => $d === 'sunday' ? 180 : 60]])
            ->all();

        $skeleton = app(WeeklyPatternService::class)
            ->build($event, $from, $from->addDays(13), $availability, [], [], null, $ladder);

        $date   = collect($skeleton['days'])
            ->first(fn ($d) => collect($d['slots'])->contains(fn ($s) => $s['type'] === 'long_run'))['date'];
        $target = collect($skeleton['days'][$date]['slots'])->firstWhere('type', 'long_run')['target_km'];

        $result = app(TrainingPlanValidator::class)->validate([
            ['date' => $date, 'type' => 'long_run', 'title' => 'Gerundet', 'distance_km' => round($target), 'duration_min' => 170],
        ], $skeleton);

        $entry = collect($result['sessions'])->firstWhere('date', $date);
        $this->assertSame(round($target), $entry['distance_km']);
    }
}
