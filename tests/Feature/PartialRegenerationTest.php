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
 * Die Neuberechnung fasst nur an, was sich ändert.
 *
 * Vorher wurde jede geplante Einheit gelöscht und vom Modell neu erfunden —
 * auch die Tage, an denen sich nichts geändert hatte. Dieser Test hält den
 * Kern fest: unveränderte Tage behalten ihren Inhalt, und wenn gar nichts
 * zu tun ist, wird das Modell überhaupt nicht gefragt.
 */
class PartialRegenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Event $event;
    private TrainingPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->onboarded()->create();

        RunnerProfile::create([
            'user_id'             => $this->user->id,
            'threshold_speed'     => 5.0,
            'weekly_availability' => $this->availability(),
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
    }

    private function availability(): array
    {
        $days = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $days[$day] = ['available' => true, 'duration_min' => 90];
        }

        return $days;
    }

    /** Das Gerüst, das der Job gleich bauen wird. */
    private function skeleton(): array
    {
        return app(PlanContextBuilder::class)->build($this->user->fresh(), $this->event)->skeleton;
    }

    /** Den Plan mit genau dem füllen, was das Gerüst vorsieht. */
    private function fillFromSkeleton(array $skeleton): void
    {
        foreach ($skeleton['days'] as $date => $day) {
            if (! $day['available'] || ! empty($day['rest'])) {
                $this->unit($date, 'rest');
                continue;
            }

            foreach ($day['slots'] ?? [] as $slot) {
                $this->unit($date, $slot['type'], $slot['target_km'] ?? null);
            }
        }
    }

    private function unit(string $date, string $type, ?float $km = null): TrainingSession
    {
        return TrainingSession::create([
            'user_id'          => $this->user->id,
            'training_plan_id' => $this->plan->id,
            'event_id'         => $this->event->id,
            'planned_date'     => $date,
            'type'             => $type,
            'title'            => "Original {$type}",
            'description'      => 'Ursprünglicher Inhalt',
            'distance_km'      => $km,
            'intensity'        => $type === 'rest' ? 'rest' : 'medium',
            'status'           => 'planned',
        ]);
    }

    /**
     * Den Generator ersetzen. Er gibt für jedes angefragte Datum eine
     * erkennbar andere Einheit zurück und merkt sich den Kontext.
     */
    private function fakeGenerator(?PlanContext &$seen = null, int &$calls = 0): void
    {
        $this->mock(TrainingPlanGenerator::class, function ($m) use (&$seen, &$calls) {
            $m->shouldReceive('withCoach')->andReturnSelf();
            $m->shouldReceive('forUser')->andReturnSelf();
            $m->shouldReceive('generateEventTrainingPlan')->andReturnUsing(
                function (PlanContext $c) use (&$seen, &$calls) {
                    $seen = $c;
                    $calls++;

                    // Ein braves Modell: es haelt sich ans Geruest. Gaebe es
                    // einen anderen Typ zurueck, wuerde der Validator ihn
                    // ersetzen und dabei den Titel neu schreiben — der Test
                    // pruefte dann den Validator statt der Teil-Neuberechnung.
                    $out = [];
                    foreach ($c->skeleton['days'] as $date => $day) {
                        if ($day['finalized'] || ! empty($day['kept'])) {
                            continue;
                        }

                        $types = ($day['available'] && empty($day['rest']) && ! empty($day['slots']))
                            ? array_column($day['slots'], 'type')
                            : ['rest'];

                        foreach ($types as $type) {
                            $isRest = $type === 'rest';

                            $out[] = [
                                'date' => $date, 'type' => $type,
                                'title' => 'NEU GESCHRIEBEN', 'description' => 'vom Modell',
                                'distance_km'  => $isRest ? 0 : ($day['slots'][0]['target_km'] ?? 6),
                                'duration_min' => $isRest ? 0 : 35,
                                'pace_target'  => $isRest ? null : '6:00',
                                'zone'         => $isRest ? null : 2,
                                'intensity'    => $isRest ? 'rest' : 'low',
                            ];
                        }
                    }

                    return $out;
                }
            );
        });

        $this->mock(WebPushService::class, fn ($m) => $m->shouldReceive('sendToUser')->andReturnNull());
    }

    private function regenerate(string $reason = RegeneratePlanJob::REASON_SKIP): void
    {
        (new RegeneratePlanJob($this->user->id, $reason))->handle(
            app(TrainingPlanGenerator::class),
            app(WebPushService::class),
            app(PlanContextBuilder::class),
        );
    }

    // ── Nichts zu tun ────────────────────────────────────────────────────

    /**
     * Der grösste Gewinn: passt der Plan zum Gerüst, wird das Modell gar
     * nicht erst gefragt. Vorher lief der Aufruf auch dann.
     */
    public function test_an_unchanged_plan_never_reaches_the_model(): void
    {
        $this->fillFromSkeleton($this->skeleton());

        $calls = 0;
        $this->fakeGenerator($seen, $calls);

        $this->regenerate();

        $this->assertSame(0, $calls, 'Das Modell darf nicht gefragt werden');
        $this->assertFalse((bool) $this->plan->refresh()->needs_plan_update);
    }

    /** Und der Plan bleibt vollständig erhalten. */
    public function test_an_unchanged_plan_keeps_every_session(): void
    {
        $this->fillFromSkeleton($this->skeleton());
        $before = TrainingSession::where('user_id', $this->user->id)->count();

        $this->fakeGenerator();
        $this->regenerate();

        $this->assertSame($before, TrainingSession::where('user_id', $this->user->id)->count());
        $this->assertSame(
            0,
            TrainingSession::where('user_id', $this->user->id)->where('title', 'NEU GESCHRIEBEN')->count(),
        );
    }

    // ── Ein einzelner Tag fehlt ──────────────────────────────────────────

    /** Der Kern: nur der fehlende Tag wird geschrieben, der Rest bleibt. */
    public function test_only_the_missing_day_is_rewritten(): void
    {
        $skeleton = $this->skeleton();
        $this->fillFromSkeleton($skeleton);

        // Einen Tag herausnehmen — er ist damit veraltet.
        $gap = collect($skeleton['days'])
            ->filter(fn ($d) => $d['available'] && ! empty($d['slots']))
            ->keys()
            ->first();

        TrainingSession::where('user_id', $this->user->id)
            ->whereDate('planned_date', $gap)
            ->delete();

        $calls = 0;
        $this->fakeGenerator($seen, $calls);
        $this->regenerate();

        $rewritten = TrainingSession::where('user_id', $this->user->id)
            ->where('title', 'NEU GESCHRIEBEN')
            ->get();

        $this->assertCount(1, $rewritten, 'Genau ein Tag wurde neu geschrieben');
        $this->assertSame($gap, $rewritten->first()->planned_date->format('Y-m-d'));

        // Alle anderen tragen noch ihren urspruenglichen Inhalt.
        $this->assertGreaterThan(
            0,
            TrainingSession::where('user_id', $this->user->id)->where('description', 'Ursprünglicher Inhalt')->count(),
        );
    }

    /** Das Modell bekommt die erhaltenen Tage als Kontext genannt. */
    public function test_the_model_is_told_which_days_stand(): void
    {
        $skeleton = $this->skeleton();
        $this->fillFromSkeleton($skeleton);

        $gap = collect($skeleton['days'])
            ->filter(fn ($d) => $d['available'] && ! empty($d['slots']))
            ->keys()
            ->first();

        TrainingSession::where('user_id', $this->user->id)->whereDate('planned_date', $gap)->delete();

        $seen = null;
        $this->fakeGenerator($seen);
        $this->regenerate();

        $this->assertNotNull($seen);
        $this->assertNotEmpty($seen->keptSessions, 'Die erhaltenen Tage gehoeren in den Kontext');

        $keptDates = collect($seen->keptSessions)->pluck('date');
        $this->assertFalse($keptDates->contains($gap), 'Der offene Tag darf nicht als erhalten gelten');
    }

    // ── Der Freeze-Horizont zählt als erhalten ───────────────────────────

    /**
     * Eine automatische Neuberechnung darf die nächsten Tage nicht anfassen,
     * auch wenn ihr Gerüst-Slot sich geändert hat.
     */
    public function test_an_automatic_run_leaves_the_frozen_days_alone(): void
    {
        $skeleton = $this->skeleton();
        $this->fillFromSkeleton($skeleton);

        // Alle Einheiten der naechsten Tage auf einen falschen Typ setzen —
        // ohne Einfrieren waeren sie damit veraltet.
        $frozen = now()->addDays(RegeneratePlanJob::FREEZE_DAYS)->toDateString();
        TrainingSession::where('user_id', $this->user->id)
            ->whereDate('planned_date', '<=', $frozen)
            ->update(['type' => 'easy_run', 'title' => 'EINGEFROREN']);

        $this->fakeGenerator();
        $this->regenerate(RegeneratePlanJob::REASON_AUTO);

        $survivors = TrainingSession::where('user_id', $this->user->id)
            ->where('title', 'EINGEFROREN')
            ->whereDate('planned_date', '<=', $frozen)
            ->count();

        $this->assertGreaterThan(0, $survivors, 'Eingefrorene Tage bleiben unangetastet');
    }
}
