<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\PlanDeltaService;
use App\Services\WeeklyPatternService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Nur die betroffenen Tage neu schreiben.
 *
 * Eine Neuberechnung löschte jede geplante Einheit und liess das Modell den
 * gesamten Rest neu erfinden — auch die Tage, an denen sich nichts geändert
 * hatte. Weil ein Sprachmodell nicht deterministisch ist, kam dort jedes Mal
 * etwas anderes heraus.
 *
 * Das Gerüst dagegen ist deterministisch. Trägt ein Tag denselben Slot wie
 * vorher und steht dort bereits eine Einheit, gibt es nichts zu tun.
 */
class PlanDeltaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private TrainingPlan $plan;
    private CarbonImmutable $from;

    protected function setUp(): void
    {
        parent::setUp();

        $this->from = CarbonImmutable::parse('2026-08-17'); // ein Montag
        $this->user = User::factory()->onboarded()->create();

        $event = Event::create([
            'user_id'             => $this->user->id,
            'name'                => 'Zielrennen',
            'event_date'          => $this->from->addDays(60),
            'race_distance'       => 'half_marathon',
            'priority'            => 'A',
            'target_time_hours'   => 1,
            'target_time_minutes' => 45,
        ]);

        $this->plan = TrainingPlan::create([
            'user_id' => $this->user->id, 'event_id' => $event->id, 'sessions' => [],
        ]);
    }

    private function availability(int $minutes = 90): array
    {
        $days = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $days[$day] = ['available' => true, 'duration_min' => $minutes];
        }

        return $days;
    }

    private function skeleton(): array
    {
        return app(WeeklyPatternService::class)->build(
            $this->plan->event,
            $this->from,
            $this->from->addDays(13),
            $this->availability(),
        );
    }

    /** Genau die Einheiten anlegen, die das Gerüst vorsieht. */
    private function sessionsMatching(array $skeleton): Collection
    {
        $out = new Collection();

        foreach ($skeleton['days'] as $date => $day) {
            if (! $day['available'] || ! empty($day['rest'])) {
                $out->push($this->unit($date, 'rest'));
                continue;
            }

            foreach ($day['slots'] ?? [] as $slot) {
                $out->push($this->unit(
                    $date,
                    $slot['type'],
                    $slot['target_km'] ?? null,
                ));
            }
        }

        return $out;
    }

    private function unit(string $date, string $type, ?float $km = null): TrainingSession
    {
        return TrainingSession::create([
            'user_id'          => $this->user->id,
            'training_plan_id' => $this->plan->id,
            'event_id'         => $this->plan->event_id,
            'planned_date'     => $date,
            'type'             => $type,
            'title'            => $type,
            'description'      => '',
            'distance_km'      => $km,
            'intensity'        => $type === 'rest' ? 'rest' : 'medium',
            'status'           => 'planned',
        ]);
    }

    private function split(array $skeleton, Collection $sessions): array
    {
        return app(PlanDeltaService::class)->split($skeleton, $sessions);
    }

    // ── Der Normalfall ───────────────────────────────────────────────────

    /** Ändert sich nichts, bleibt alles stehen — und das Modell wird nicht gefragt. */
    public function test_an_unchanged_plan_has_nothing_stale(): void
    {
        $skeleton = $this->skeleton();
        $delta    = $this->split($skeleton, $this->sessionsMatching($skeleton));

        $this->assertSame([], $delta['stale']);
        $this->assertNotEmpty($delta['keep']);
    }

    /** Ein leerer Plan ist überall veraltet. */
    public function test_an_empty_plan_is_stale_everywhere(): void
    {
        $skeleton = $this->skeleton();
        $delta    = $this->split($skeleton, new Collection());

        $this->assertSame([], $delta['keep']);
        $this->assertCount(count($skeleton['days']), $delta['stale']);
    }

    // ── Einzelne Tage ────────────────────────────────────────────────────

    /** Ein Tag mit falschem Typ wird neu geschrieben, die anderen nicht. */
    public function test_only_the_changed_day_is_stale(): void
    {
        $skeleton = $this->skeleton();
        $sessions = $this->sessionsMatching($skeleton);

        // Einen Tag mit Pflichteinheit auf etwas anderes umbiegen.
        $target = collect($skeleton['days'])
            ->filter(fn ($d) => $d['available'] && ! empty($d['slots']) && empty($d['rest']))
            ->keys()
            ->first();

        $sessions = $sessions->map(function ($s) use ($target) {
            if ($s->planned_date->format('Y-m-d') === $target) {
                $s->type = 'easy_run';
            }
            return $s;
        });

        $delta = $this->split($skeleton, $sessions);

        // Entweder war dort schon easy_run geplant — dann ist nichts veraltet.
        if ($delta['stale'] !== []) {
            $this->assertSame([$target], $delta['stale']);
        }

        $this->assertNotContains($target, $delta['keep'] === [] ? [$target] : $delta['keep']);
    }

    /** Fehlt an einem vorgesehenen Tag die Einheit, muss er geschrieben werden. */
    public function test_a_missing_day_is_stale(): void
    {
        $skeleton = $this->skeleton();
        $sessions = $this->sessionsMatching($skeleton);

        $dropped  = $sessions->first()->planned_date->format('Y-m-d');
        $sessions = $sessions->reject(fn ($s) => $s->planned_date->format('Y-m-d') === $dropped);

        $delta = $this->split($skeleton, $sessions);

        $this->assertContains($dropped, $delta['stale']);
        $this->assertNotContains($dropped, $delta['keep']);
    }

    /** Ein Ruhetag, an dem eine Einheit steht, ist veraltet. */
    public function test_a_session_on_a_rest_day_is_stale(): void
    {
        $skeleton = $this->skeleton();
        $sessions = $this->sessionsMatching($skeleton);

        $restDate = collect($skeleton['days'])->filter(fn ($d) => ! empty($d['rest']))->keys()->first();
        $this->assertNotNull($restDate, 'Kein Ruhetag im Geruest');

        $sessions = $sessions->map(function ($s) use ($restDate) {
            if ($s->planned_date->format('Y-m-d') === $restDate) {
                $s->type = 'tempo_run';
            }
            return $s;
        });

        $this->assertContains($restDate, $this->split($skeleton, $sessions)['stale']);
    }

    // ── Der lange Lauf hängt an der Leiter ───────────────────────────────

    /**
     * Verschiebt sich die Leiter, muss der lange Lauf neu geschrieben werden
     * — auch wenn der Typ derselbe geblieben ist.
     */
    public function test_a_long_run_that_drifted_from_the_ladder_is_stale(): void
    {
        $skeleton = $this->skeleton();

        // Eine Zieldistanz von Hand setzen, damit der Test nicht davon
        // abhaengt, ob die Leiter im Fenster schon greift.
        $date = collect($skeleton['days'])
            ->filter(fn ($d) => collect($d['slots'] ?? [])->contains(fn ($s) => $s['type'] === 'long_run'))
            ->keys()
            ->first();
        $this->assertNotNull($date, 'Kein langer Lauf im Geruest');

        foreach ($skeleton['days'][$date]['slots'] as $i => $slot) {
            if ($slot['type'] === 'long_run') {
                $skeleton['days'][$date]['slots'][$i]['target_km'] = 20.0;
            }
        }

        $matching = $this->sessionsMatching($skeleton);

        // 20 km geplant, 14 km stehen im Plan — das sind 30 % daneben.
        $drifted = $matching->map(function ($s) use ($date) {
            if ($s->planned_date->format('Y-m-d') === $date && $s->type === 'long_run') {
                $s->distance_km = 14.0;
            }
            return $s;
        });

        $this->assertContains($date, $this->split($skeleton, $drifted)['stale']);

        // Innerhalb der Toleranz bleibt er stehen — runde Zahlen sind gewollt.
        $rounded = $matching->map(function ($s) use ($date) {
            if ($s->planned_date->format('Y-m-d') === $date && $s->type === 'long_run') {
                $s->distance_km = 19.0;
            }
            return $s;
        });

        $this->assertContains($date, $this->split($skeleton, $rounded)['keep']);
    }

    // ── Abgeschlossene Tage gehören dem Athleten ─────────────────────────

    public function test_finalized_days_appear_in_neither_list(): void
    {
        $finalized = $this->from->addDays(2)->format('Y-m-d');

        $skeleton = app(WeeklyPatternService::class)->build(
            $this->plan->event,
            $this->from,
            $this->from->addDays(13),
            $this->availability(),
            [],
            [$finalized],
        );

        $delta = $this->split($skeleton, new Collection());

        $this->assertNotContains($finalized, $delta['stale']);
        $this->assertNotContains($finalized, $delta['keep']);
    }

    /** Eine optionale Zweiteinheit darf fehlen, ohne den Tag zu entwerten. */
    public function test_a_missing_optional_slot_does_not_make_the_day_stale(): void
    {
        $skeleton = $this->skeleton();

        $date = collect($skeleton['days'])
            ->filter(fn ($d) => collect($d['slots'] ?? [])->contains(fn ($s) => ! empty($s['optional'])))
            ->keys()
            ->first();

        if ($date === null) {
            $this->markTestSkipped('Kein Tag mit optionaler Zweiteinheit im Geruest');
        }

        // Nur die Pflichteinheit anlegen.
        $required = collect($skeleton['days'][$date]['slots'])->reject(fn ($s) => ! empty($s['optional']));
        $sessions = new Collection();
        foreach ($required as $slot) {
            $sessions->push($this->unit($date, $slot['type'], $slot['target_km'] ?? null));
        }

        $delta = $this->split(['days' => [$date => $skeleton['days'][$date]]], $sessions);

        $this->assertSame([$date], $delta['keep']);
    }
}
