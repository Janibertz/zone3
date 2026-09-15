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
 * Am Renntag wird gelaufen, nicht trainiert.
 *
 * Gemeldet: „Ich habe nächste Woche am Freitag ein 5k Rennen und am Sonntag
 * ein 24km Wattlauf in Cuxhaven. Ich soll aber an beiden Tagen Trainings
 * laufen … am Donnerstag steht sogar Ruhetag für den Wettkampf. Es wird
 * aber an dem jeweiligen Tag gar nicht berücksichtigt."
 *
 * Der Fehler war eine doppelte Wahrheit über denselben Tag. Der Prompt
 * kannte die anderen Rennen („an diesen Tagen KEIN Training — type=rest"),
 * das Gerüst kannte sie nicht. Das Modell hielt sich an den Prompt und
 * lieferte einen Ruhetag; der Validator sah, dass die Einheit aus dem
 * Gerüst fehlt, und ersetzte den Ruhetag durch sie. Am Renntag stand dann
 * ein Tempolauf, am Tag des 24-km-Wattlaufs ein langer Lauf.
 *
 * Der erste Test hier ist die Regression: dieselbe Modellantwort, derselbe
 * Validator, anderes Ergebnis.
 */
class RaceDayInPlanTest extends TestCase
{
    use RefreshDatabase;

    /** Ein Montag, damit die Wochengrenzen im Test berechenbar bleiben. */
    private const MONDAY = '2026-08-17';

    private function event(string $distance = 'marathon', int $daysUntil = 60): Event
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        return Event::create([
            'user_id'             => $user->id,
            'name'                => 'Zielrennen',
            'event_date'          => CarbonImmutable::parse(self::MONDAY)->addDays($daysUntil),
            'race_distance'       => $distance,
            'priority'            => 'A',
            'target_time_hours'   => 3,
            'target_time_minutes' => 45,
        ]);
    }

    private function availability(int $minutes = 120, array $except = []): array
    {
        $days = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $days[$day] = in_array($day, $except, true)
                ? ['available' => false, 'duration_min' => 0]
                : ['available' => true,  'duration_min' => $minutes];
        }

        return $days;
    }

    /**
     * @param  array<string,array>  $races  Datum => ['name','km','priority','target_min',…]
     */
    private function build(Event $event, array $races, ?array $availability = null, int $days = 14): array
    {
        $from = CarbonImmutable::parse(self::MONDAY);

        return app(WeeklyPatternService::class)->build(
            $event,
            $from,
            $from->addDays($days - 1),
            $availability ?? $this->availability(),
            [], [], null, null, null, null,
            $races,
        );
    }

    /**
     * Dasselbe Geruest, aber mit Umfangsbudget — so wie in Produktion.
     *
     * `target_km` und `target_min` an den Slots entstehen erst in
     * `applyVolumeBudget()`, und das laeuft nur mit Volumendaten und einer
     * Planungspace. Wer die weglaesst, testet ein halbes Geruest: genau
     * daran sind drei dieser Tests zuerst gescheitert, ohne dass am Code
     * etwas falsch war.
     */
    private function buildWithVolume(Event $event, array $races, ?array $availability = null): array
    {
        $from = CarbonImmutable::parse(self::MONDAY);

        return app(WeeklyPatternService::class)->build(
            $event,
            $from,
            $from->addDays(13),
            $availability ?? $this->availability(),
            [], [], null, null,
            ['has_data' => true, 'next_week_max' => 60.0],
            330, // 5:30 min/km als Planungspace
            $races,
        );
    }

    private function slotTypes(array $skeleton, string $date): array
    {
        return collect($skeleton['days'][$date]['slots'] ?? [])->pluck('type')->all();
    }

    // ── Die Regression ───────────────────────────────────────────────────

    /**
     * Das Modell liefert, was der Prompt verlangt: einen Ruhetag am
     * Renntag. Vorher machte der Validator daraus einen Tempolauf.
     */
    public function test_the_validator_no_longer_puts_training_on_a_race_day(): void
    {
        $event    = $this->event();
        $raceDate = '2026-08-21'; // Freitag

        $skeleton = $this->build($event, [
            $raceDate => ['date' => $raceDate, 'name' => 'Sportcheck 5k Speed', 'km' => 5.0, 'priority' => 'B'],
        ]);

        // Genau die Antwort, die der Prompt verlangt hat.
        $answer = [[
            'date' => $raceDate, 'type' => 'rest', 'title' => 'Ruhetag (Wettkampf)',
            'description' => 'Heute ist das Rennen.', 'distance_km' => 0, 'duration_min' => 0,
            'intensity' => 'rest',
        ]];

        $result = app(TrainingPlanValidator::class)
            ->validate($answer, $skeleton, $event->event_date->toDateString());

        $onRaceDay = collect($result['sessions'])->firstWhere('date', $raceDate);

        $this->assertSame('race_prep', $onRaceDay['type'], 'Am Renntag steht das Rennen, kein Training');
        $this->assertSame('Sportcheck 5k Speed', $onRaceDay['title']);
        $this->assertEqualsWithDelta(5.0, $onRaceDay['distance_km'], 0.1);

        $runs = collect($result['sessions'])
            ->where('date', $raceDate)
            ->whereIn('type', ['easy_run', 'tempo_run', 'interval', 'long_run']);

        $this->assertCount(0, $runs, 'Kein Trainingslauf am Renntag');
    }

    // ── Das Gerüst ───────────────────────────────────────────────────────

    public function test_a_race_day_carries_the_race_in_the_skeleton(): void
    {
        $race = '2026-08-21';

        $skeleton = $this->build($this->event(), [
            $race => ['date' => $race, 'name' => 'Sportcheck 5k Speed', 'km' => 5.0, 'priority' => 'B'],
        ]);

        $this->assertContains('race_prep', $this->slotTypes($skeleton, $race));

        $slot = collect($skeleton['days'][$race]['slots'])->firstWhere('type', 'race_prep');

        $this->assertSame('Sportcheck 5k Speed', $slot['race']['name']);
        $this->assertTrue($slot['hard'], 'Ein Rennen ist eine harte Einheit');
    }

    /**
     * Ein 24-km-Rennen IST der lange Lauf der Woche. Ohne diese Zuordnung
     * legte das Gerüst daneben noch einen zweiten.
     */
    public function test_a_long_race_replaces_the_weeks_long_run(): void
    {
        $race = '2026-08-23'; // Sonntag derselben Woche

        $skeleton = $this->build($this->event(), [
            $race => ['date' => $race, 'name' => 'Wattlauf Cuxhaven', 'km' => 24.6, 'priority' => 'B'],
        ]);

        $week = collect(array_slice($skeleton['days'], 0, 7, true))
            ->flatMap(fn ($day) => collect($day['slots'])->pluck('type'))
            ->all();

        $this->assertContains('race_prep', $week);
        $this->assertNotContains('long_run', $week, 'Kein zweiter langer Lauf neben dem 24-km-Rennen');
    }

    /** Ein 5er ersetzt keinen langen Lauf — dafür ist er zu kurz. */
    public function test_a_short_race_leaves_the_long_run_in_place(): void
    {
        $race = '2026-08-18'; // Dienstag

        $skeleton = $this->build($this->event(), [
            $race => ['date' => $race, 'name' => 'Sportcheck 5k Speed', 'km' => 5.0, 'priority' => 'B'],
        ]);

        $week = collect(array_slice($skeleton['days'], 0, 7, true))
            ->flatMap(fn ($day) => collect($day['slots'])->pluck('type'))
            ->all();

        $this->assertContains('long_run', $week);
    }

    /**
     * Wer am Sonntag ein Rennen laufen hat, hat an dem Tag Zeit — auch wenn
     * im Wochenraster etwas anderes steht.
     */
    public function test_a_race_beats_the_weekly_grid(): void
    {
        $race = '2026-08-23'; // Sonntag

        $skeleton = $this->build(
            $this->event(),
            [$race => ['date' => $race, 'name' => 'Wattlauf Cuxhaven', 'km' => 24.6, 'priority' => 'B']],
            $this->availability(120, ['sunday']),
        );

        $this->assertTrue($skeleton['days'][$race]['available'], 'Der Renntag ist verfuegbar');
        $this->assertContains('race_prep', $this->slotTypes($skeleton, $race));
    }

    /**
     * Ein Vereinstermin am Renntag fällt aus — der Athlet ist beim Rennen.
     */
    public function test_a_race_beats_a_fixed_club_appointment(): void
    {
        $race = '2026-08-18'; // Dienstag

        $availability = $this->availability();
        $availability['tuesday']['fixed'] = ['type' => 'interval', 'label' => 'Laufclub'];

        $skeleton = $this->build(
            $this->event(),
            [$race => ['date' => $race, 'name' => 'Sportcheck 5k Speed', 'km' => 5.0, 'priority' => 'B']],
            $availability,
        );

        $slot = collect($skeleton['days'][$race]['slots'])->first();

        $this->assertSame('race_prep', $slot['type']);
        $this->assertSame('Sportcheck 5k Speed', $slot['label']);
    }

    /** Ohne Rennen bleibt alles, wie es war. */
    public function test_without_races_nothing_changes(): void
    {
        $skeleton = $this->build($this->event(), []);

        $types = collect($skeleton['days'])->flatMap(fn ($d) => collect($d['slots'])->pluck('type'))->unique();

        $this->assertNotContains('race_prep', $types);
        $this->assertContains('long_run', $types);
    }

    // ── Das Zielrennen des Plans ─────────────────────────────────────────

    /**
     * Gemeldet: „Jetzt steht für den Berlin Marathon ein Langer Lauf von 9km
     * an obwohl dort mein Rennen stattfindet."
     *
     * Der Fix fuer die Nebenrennen kam aus `otherEvents()` — und die Methode
     * schliesst per `id != $event->id` ausgerechnet das Zielrennen aus. Am
     * Renntag des Plans stand damit weiterhin Training, waehrend der ganze
     * Taper davor auf dieses Rennen zulief.
     */
    public function test_the_plans_own_race_is_a_race_too(): void
    {
        // Dieser Test geht durch den echten PlanContextBuilder, und der
        // rechnet ab HEUTE — nicht ab dem festen Montag der anderen Tests.
        // Das Rennen muss deshalb wirklich in der Zukunft liegen.
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        $event = Event::create([
            'user_id'             => $user->id,
            'name'                => 'Berlin Marathon',
            'event_date'          => now()->addDays(6),
            'race_distance'       => 'marathon',
            'priority'            => 'A',
            'target_time_hours'   => 3,
            'target_time_minutes' => 45,
        ]);

        $skeleton = app(\App\Services\PlanContextBuilder::class)
            ->build($user, $event)
            ->skeleton;

        $raceDate = $event->event_date->format('Y-m-d');
        $types    = collect($skeleton['days'][$raceDate]['slots'] ?? [])->pluck('type');

        $this->assertContains('race_prep', $types, 'Am Renntag des Plans steht das Rennen');
        $this->assertNotContains('long_run', $types, 'Und kein Trainingslauf');
    }

    // ── Die Zielzeit ─────────────────────────────────────────────────────

    /**
     * Gemeldet: „Ich habe den Sportcheck lauf mit ziel 20 min angeben im plan
     * steht aber 27 min."
     *
     * Die 27 Minuten waren 5 km in der Zonenpace — eine Schaetzung, die eine
     * eingetragene Vorgabe uebersteuerte. Schlimmer als die Zahl war die
     * Folge: das Modell pacte den 5er im Marathontempo.
     */
    public function test_the_entered_target_time_beats_the_estimate(): void
    {
        $race = '2026-08-21';

        $skeleton = $this->buildWithVolume($this->event(), [
            $race => [
                'date' => $race, 'name' => 'Sportcheck 5k Speed', 'km' => 5.0,
                'priority' => 'B', 'target_min' => 20, 'target_pace' => '4:00',
            ],
        ]);

        $slot = collect($skeleton['days'][$race]['slots'])->firstWhere('type', 'race_prep');

        $this->assertSame(20, $slot['target_min'], 'Die eingetragenen 20 Minuten, nicht die Schaetzung');
        $this->assertEqualsWithDelta(5.0, $slot['target_km'], 0.1);
    }

    /** Ohne Zielzeit bleibt es bei der Schaetzung — der Wattlauf hat keine. */
    public function test_without_a_target_time_the_estimate_stands(): void
    {
        $race = '2026-08-23';

        $skeleton = $this->buildWithVolume($this->event(), [
            $race => ['date' => $race, 'name' => 'Wattlauf Cuxhaven', 'km' => 24.6, 'priority' => 'B'],
        ]);

        $slot = collect($skeleton['days'][$race]['slots'])->firstWhere('type', 'race_prep');

        $this->assertGreaterThan(0, $slot['target_min']);
        $this->assertEqualsWithDelta(24.6, $slot['target_km'], 0.1);
    }

    /**
     * Ein Marathon dauert so lange, wie er dauert.
     *
     * Das Wochenraster sagte fuer Sonntag „max. 180 min", und der Validator
     * machte daraus gehorsam „33,8 km in 180 min" — vier Fuenftel eines
     * Marathons. Ein Zeitbudget ist eine Aussage ueber Trainingstage.
     */
    public function test_a_race_is_never_cut_down_to_the_daily_budget(): void
    {
        $race  = '2026-08-23';
        $event = $this->event();

        $skeleton = $this->buildWithVolume(
            $event,
            [$race => [
                'date' => $race, 'name' => 'Berlin Marathon', 'km' => 42.195,
                'priority' => 'A', 'target_min' => 225, 'target_pace' => '5:19',
            ]],
            // Nur 90 Minuten Zeit am Tag — deutlich weniger als das Rennen.
            $this->availability(90),
        );

        $result = app(TrainingPlanValidator::class)->validate([
            ['date' => $race, 'type' => 'rest', 'title' => 'Ruhetag', 'description' => '',
             'distance_km' => 0, 'duration_min' => 0, 'intensity' => 'rest'],
        ], $skeleton, $event->event_date->toDateString());

        $onRaceDay = collect($result['sessions'])->firstWhere('type', 'race_prep');

        $this->assertSame(225, $onRaceDay['duration_min'], 'Die volle Zielzeit, nicht das Tagesbudget');
        $this->assertEqualsWithDelta(42.2, $onRaceDay['distance_km'], 0.1, 'Die volle Distanz');
        $this->assertSame('5:19', $onRaceDay['pace_target'], 'Und das Zieltempo daraus');
    }

    /** Zielzeit und Distanz ergeben das Zieltempo — abgerundet, wie eine Vorgabe. */
    public function test_the_target_pace_comes_from_time_and_distance(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        $race = Event::create([
            'user_id' => $user->id, 'name' => '5er', 'event_date' => now()->addDays(10),
            'race_distance' => '5km', 'priority' => 'B',
            'target_time_hours' => 0, 'target_time_minutes' => 20,
        ]);

        $this->assertSame(20, $race->target_minutes);
        $this->assertEqualsWithDelta(240.0, $race->target_pace_seconds, 0.1);

        $ohne = Event::create([
            'user_id' => $user->id, 'name' => 'Ohne Ziel', 'event_date' => now()->addDays(10),
            'race_distance' => 'custom', 'distance_km' => 24.6, 'priority' => 'B',
            'target_time_hours' => 0, 'target_time_minutes' => 0,
        ]);

        $this->assertNull($ohne->target_minutes, 'Keine Zeit eingetragen heisst keine Zeit');
        $this->assertNull($ohne->target_pace_seconds);
    }

    // ── Die Distanz als Zahl ─────────────────────────────────────────────

    /**
     * `distance_label` liest sich gut, rechnet aber nicht. Sobald ein Rennen
     * Wochenumfang belegt, braucht das Gerüst die Zahl.
     */
    public function test_every_race_reports_its_distance_as_a_number(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        $make = fn (string $distance, ?float $km = null) => Event::create([
            'user_id' => $user->id, 'name' => 'X', 'event_date' => now()->addDays(30),
            'race_distance' => $distance, 'distance_km' => $km, 'priority' => 'B',
            'target_time_hours' => 1, 'target_time_minutes' => 0,
        ]);

        $this->assertEqualsWithDelta(5.0, $make('5km')->race_km, 0.01);
        $this->assertEqualsWithDelta(10.0, $make('10km')->race_km, 0.01);
        $this->assertEqualsWithDelta(21.0975, $make('half_marathon')->race_km, 0.01);
        $this->assertEqualsWithDelta(42.195, $make('marathon')->race_km, 0.01);
        $this->assertEqualsWithDelta(24.6, $make('custom', 24.6)->race_km, 0.01);
    }
}
