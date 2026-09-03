<?php

namespace Tests\Feature;

use App\Jobs\RegeneratePlanJob;
use App\Models\Event;
use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\AI\TrainingPlanGenerator;
use App\Services\PlanContext;
use App\Services\PlanContextBuilder;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kein Loch im Plan.
 *
 * Zweimal stand in Jans Plan ein Tag ohne jede Einheit — am 31.08. und am
 * 03.09. Beide Male sah die App an der Stelle einfach leer aus, beide Male
 * stand der Tag im Gerüst, und beide Male meldete der Verlauf brav eine
 * Änderung für genau dieses Datum.
 *
 * Dass der Verlauf das nicht auffangen konnte, liegt an ihm selbst: er wird
 * aus der MODELLAUSGABE geschrieben und noch bevor die Einheiten in der
 * Datenbank landen. Er beschreibt also die Absicht, nicht das Ergebnis — und
 * meldete deshalb eine Einheit, die es nie gab.
 *
 * Der Weg dorthin hat mehrere Weichen: das Modell darf einen Tag weglassen,
 * der Validator trägt ihn nur nach, wenn er weder `finalized` noch `kept`
 * ist, und die Anlege-Schleife überspringt Tage, die sie für belegt hält.
 * Jede für sich ist richtig. Deshalb prüft dieser Test nicht die Weichen,
 * sondern das Ergebnis: was am Ende in der Datenbank steht.
 */
class PlanGapTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Event $event;
    private TrainingPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->onboarded()->create();

        $availability = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $availability[$day] = ['available' => true, 'duration_min' => 90];
        }

        RunnerProfile::create([
            'user_id'             => $this->user->id,
            'threshold_speed'     => 5.0,
            'weekly_availability' => $availability,
        ]);

        $this->event = Event::create([
            'user_id'             => $this->user->id,
            'name'                => 'Zielrennen',
            'event_date'          => now()->addDays(60),
            'race_distance'       => 'half_marathon',
            'priority'            => 'A',
            'target_time_hours'   => 1,
            'target_time_minutes' => 45,
        ]);

        $this->plan = TrainingPlan::create([
            'user_id' => $this->user->id, 'event_id' => $this->event->id, 'sessions' => [],
        ]);
        $this->plan->forceFill(['is_active' => true, 'needs_plan_update' => true])->save();

        $this->mock(WebPushService::class, fn ($m) => $m->shouldReceive('sendToUser')->andReturnNull());
    }

    private function skeletonDates(): array
    {
        $skeleton = app(PlanContextBuilder::class)->build($this->user->fresh(), $this->event)->skeleton;

        return array_keys($skeleton['days']);
    }

    /**
     * Ein Modell, das einen Tag verschluckt.
     *
     * Genau das ist passiert: der Verlauf meldete `to: null` — die Ausgabe
     * enthielt das Datum nicht mehr.
     */
    private function generatorSkipping(string $skipDate): void
    {
        $this->mock(TrainingPlanGenerator::class, function ($m) use ($skipDate) {
            $m->shouldReceive('withCoach')->andReturnSelf();
            $m->shouldReceive('forUser')->andReturnSelf();
            $m->shouldReceive('generateEventTrainingPlan')->andReturnUsing(
                function (PlanContext $c) use ($skipDate) {
                    $out = [];

                    foreach ($c->skeleton['days'] as $date => $day) {
                        if ($date === $skipDate) {
                            continue;
                        }
                        if ($day['finalized'] || ! empty($day['kept'])) {
                            continue;
                        }

                        $isRest = ! $day['available'] || ! empty($day['rest']) || empty($day['slots']);

                        $out[] = [
                            'date'         => $date,
                            'type'         => $isRest ? 'rest' : ($day['slots'][0]['type'] ?? 'easy_run'),
                            'title'        => 'Vom Modell',
                            'description'  => 'Inhalt',
                            'distance_km'  => $isRest ? 0 : ($day['slots'][0]['target_km'] ?? 6),
                            'duration_min' => $isRest ? 0 : 35,
                            'pace_target'  => $isRest ? null : '6:00',
                            'zone'         => $isRest ? null : 2,
                            'intensity'    => $isRest ? 'rest' : 'low',
                        ];
                    }

                    return $out;
                }
            );
        });
    }

    private function regenerate(string $reason = RegeneratePlanJob::REASON_MANUAL): void
    {
        (new RegeneratePlanJob($this->user->id, $reason))->handle(
            app(TrainingPlanGenerator::class),
            app(WebPushService::class),
            app(PlanContextBuilder::class),
        );
    }

    /** Alle Daten, an denen der Athlet hinterher irgendetwas stehen hat. */
    private function occupiedDates(): array
    {
        return TrainingSession::where('user_id', $this->user->id)
            ->pluck('planned_date')
            ->map(fn ($d) => $d->format('Y-m-d'))
            ->unique()
            ->values()
            ->all();
    }

    // ── Das Ergebnis, nicht die Absicht ──────────────────────────────────

    public function test_a_day_the_model_leaves_out_still_gets_an_entry(): void
    {
        $dates = $this->skeletonDates();
        $this->assertNotEmpty($dates);

        // Ein Tag mitten im Fenster — ein Loch am Rand faellt weniger auf,
        // beide beobachteten Faelle lagen mittendrin.
        $skipped = $dates[intdiv(count($dates), 2)];

        $this->generatorSkipping($skipped);
        $this->regenerate();

        $this->assertContains(
            $skipped,
            $this->occupiedDates(),
            "Der {$skipped} steht im Gerüst, hat nach der Neuberechnung aber keine einzige Einheit",
        );
    }

    /**
     * Erfreulich, und deshalb festgehalten: lässt das Modell einen Tag aus,
     * stellt der Validator den Slot aus dem Gerüst wieder her — mit dem
     * richtigen Typ, nicht als Ruhetag. Die Kontrolle danach greift in
     * diesem Fall gar nicht.
     */
    public function test_the_validator_restores_the_skeletons_intent(): void
    {
        $dates   = $this->skeletonDates();
        $skipped = $dates[intdiv(count($dates), 2)];

        $this->generatorSkipping($skipped);
        $this->regenerate();

        $session = TrainingSession::where('user_id', $this->user->id)
            ->whereDate('planned_date', $skipped)
            ->first();

        $this->assertNotNull($session);
        $this->assertNotSame('', $session->title);
    }

    /**
     * Und die Kontrolle selbst, direkt.
     *
     * Sie ist die letzte Instanz für den Fall, den der Validator nicht
     * abdeckt: einen Tag, den er als `finalized` oder `kept` überspringt,
     * dessen Einheit aber trotzdem nicht mehr da ist. Genau so sah es im
     * Plan aus — Tag im Gerüst, nichts in der Datenbank.
     */
    public function test_the_guard_fills_a_day_that_nothing_else_covered(): void
    {
        $gap  = now()->addDays(3)->format('Y-m-d');
        $seal = new \ReflectionMethod(RegeneratePlanJob::class, 'sealGaps');

        $seal->invoke(
            new RegeneratePlanJob($this->user->id),
            $this->user,
            $this->event,
            $this->plan,
            ['days' => [$gap => ['available' => true, 'slots' => []]]],
        );

        $session = TrainingSession::where('user_id', $this->user->id)
            ->whereDate('planned_date', $gap)
            ->first();

        $this->assertNotNull($session, 'Der Gerüsttag war leer und blieb es');
        $this->assertSame('rest', $session->type, 'Lieber ehrlich nichts als ein erfundenes Training');
    }

    /**
     * Sie darf nur echte Löcher füllen — ein Tag mit einer abgeschlossenen
     * Einheit ist keins.
     */
    public function test_the_guard_leaves_an_occupied_day_alone(): void
    {
        $date = now()->addDays(4)->format('Y-m-d');

        TrainingSession::create([
            'user_id'          => $this->user->id,
            'training_plan_id' => $this->plan->id,
            'event_id'         => $this->event->id,
            'planned_date'     => $date,
            'type'             => 'easy_run',
            'title'            => 'Schon gelaufen',
            'distance_km'      => 8,
            'intensity'        => 'low',
            'status'           => 'completed',
        ]);

        $seal = new \ReflectionMethod(RegeneratePlanJob::class, 'sealGaps');
        $seal->invoke(
            new RegeneratePlanJob($this->user->id),
            $this->user,
            $this->event,
            $this->plan,
            ['days' => [$date => ['available' => true, 'slots' => []]]],
        );

        $this->assertSame(
            1,
            TrainingSession::where('user_id', $this->user->id)->whereDate('planned_date', $date)->count(),
            'Neben eine abgeschlossene Einheit gehoert kein Ruhetag',
        );
    }

    /**
     * Die Kontrolle darf nur echte Löcher füllen. Ein Tag, an dem der Athlet
     * bereits gelaufen ist, ist keins — dort einen Ruhetag danebenzusetzen
     * wäre schlimmer als das Problem.
     */
    public function test_a_day_that_already_holds_something_is_left_alone(): void
    {
        $dates   = $this->skeletonDates();
        $skipped = $dates[intdiv(count($dates), 2)];

        TrainingSession::create([
            'user_id'          => $this->user->id,
            'training_plan_id' => $this->plan->id,
            'event_id'         => $this->event->id,
            'planned_date'     => $skipped,
            'type'             => 'easy_run',
            'title'            => 'Schon gelaufen',
            'distance_km'      => 8,
            'intensity'        => 'low',
            'status'           => 'completed',
        ]);

        $this->generatorSkipping($skipped);
        $this->regenerate();

        $sessions = TrainingSession::where('user_id', $this->user->id)
            ->whereDate('planned_date', $skipped)
            ->get();

        $this->assertCount(1, $sessions, 'Neben eine abgeschlossene Einheit gehoert kein Ruhetag');
        $this->assertSame('Schon gelaufen', $sessions->first()->title);
    }

    /**
     * Und der Normalfall darf sich nicht ändern: liefert das Modell alles,
     * entsteht kein zusätzlicher Ruhetag.
     */
    public function test_a_complete_answer_gets_nothing_added(): void
    {
        $this->generatorSkipping('1999-01-01');
        $this->regenerate();

        $dates = $this->skeletonDates();
        $rest  = TrainingSession::where('user_id', $this->user->id)
            ->where('type', 'rest')
            ->where('description', 'like', '%keine Einheit zurück%')
            ->count();

        $this->assertSame(0, $rest, 'Ohne Lücke darf die Kontrolle nichts eintragen');
        $this->assertNotEmpty($dates);
    }
}
