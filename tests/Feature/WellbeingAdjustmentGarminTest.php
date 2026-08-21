<?php

namespace Tests\Feature;

use App\Jobs\AdjustPlanForWellbeingJob;
use App\Models\GarminDailyMetric;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WellbeingEntry;
use App\Services\GarminHealthSummary;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Der Plan-Prompt sagt seit gestern „gemessene Erholung schlägt die
 * Selbsteinschätzung". Gehandelt wurde danach nirgends: die tägliche
 * Anpassung las ausschließlich die manuellen 1–10-Werte des Check-ins.
 * HRV, Ruhepuls, Schlaf und Readiness lagen daneben in der Datenbank und
 * wurden nicht angefasst.
 *
 * Wer sich gut fühlt, aber mit einer HRV 20 % unter der Grundlinie
 * aufsteht, bekam sein Intervalltraining — die Uhr sieht die Erholung, das
 * Gefühl sieht die Motivation.
 */
class WellbeingAdjustmentGarminTest extends TestCase
{
    use RefreshDatabase;

    private function athlete(): array
    {
        $user = User::factory()->create();

        $plan = TrainingPlan::create([
            'user_id'   => $user->id,
            'sessions'  => [],
            'is_active' => true,
        ]);

        $sessionId = DB::table('training_sessions')->insertGetId([
            'user_id'          => $user->id,
            'training_plan_id' => $plan->id,
            'planned_date'     => now()->toDateString(),
            'type'             => 'interval',
            'title'            => 'Intervalle 5×1000 m',
            'description'      => 'Nach dem Einlaufen fünf harte Kilometer.',
            'distance_km'      => 12,
            'duration_min'     => 60,
            'intensity'        => 'high',
            'zone'             => 4,
            'status'           => 'planned',
            'sort_order'       => 0,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return [$user, $sessionId];
    }

    /** Ein Athlet, der sich gut fühlt — der Check-in gibt keinen Anlass. */
    private function goodCheckin(User $user): WellbeingEntry
    {
        return WellbeingEntry::create([
            'user_id'         => $user->id,
            'date'            => now()->toDateString(),
            'energy_level'    => 8,
            'mood'            => 8,
            'sleep_quality'   => 7,
            'muscle_soreness' => 2,
            'stress_level'    => 3,
            'is_sick'         => false,
            'is_injured'      => false,
        ]);
    }

    private function metric(User $user, int $daysAgo, float $hrv, int $rhr, float $sleep = 7.5, ?int $readiness = 70): void
    {
        GarminDailyMetric::create([
            'user_id'            => $user->id,
            'date'               => now()->subDays($daysAgo)->toDateString(),
            'hrv'                => $hrv,
            'resting_hr'         => $rhr,
            'sleep_hours'        => $sleep,
            'training_readiness' => $readiness,
        ]);
    }

    private function promptSent(): string
    {
        $body = json_decode(Http::recorded()[0][0]->body(), true);

        return collect($body['messages'])->pluck('content')->implode("\n");
    }

    // ── Die Werte erreichen den Prompt ───────────────────────────────────

    public function test_todays_measurements_reach_the_adjustment(): void
    {
        [$user, $sessionId] = $this->athlete();
        $wellbeing = $this->goodCheckin($user);

        // Grundlinie: 60 ms HRV, Ruhepuls 50.
        foreach (range(1, 14) as $d) {
            $this->metric($user, $d, 60, 50);
        }
        // Heute: HRV eingebrochen, Ruhepuls hoch, kaum geschlafen.
        $this->metric($user, 0, 45, 56, 4.5, 28);

        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'type' => 'easy_run', 'title' => 'Locker statt Intervalle',
                'description' => 'HRV 25 % unter der Grundlinie — heute ruhig.',
                'distance_km' => 8, 'duration_min' => 45, 'pace_target' => '5:30', 'zone' => 2, 'intensity' => 'low',
            ])], 'finish_reason' => 'stop']],
        ])]);

        (new AdjustPlanForWellbeingJob($user->id, $wellbeing->id))
            ->handle(app(\App\Services\AI\SessionContentService::class), app(GarminHealthSummary::class));

        $prompt = $this->promptSent();

        $this->assertStringContainsString('Gemessene Werte der Uhr', $prompt);
        $this->assertStringContainsString('HRV heute Nacht', $prompt);
        $this->assertStringContainsString('Auffällig', $prompt, 'Die Warnsignale müssen benannt sein');
        $this->assertStringContainsString('Training Readiness: 28/100', $prompt);

        // Und die Antwort landet in der Einheit.
        $session = TrainingSession::find($sessionId);
        $this->assertSame('easy_run', $session->type);
        $this->assertSame('low', $session->intensity);
    }

    /** Ohne Uhr steht das ausdrücklich da — sonst liest das Modell Schweigen als „alles gut". */
    public function test_without_a_watch_the_prompt_says_so(): void
    {
        [$user] = $this->athlete();
        $wellbeing = $this->goodCheckin($user);

        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => '{"type":"interval","title":"x","description":"y","distance_km":12,"duration_min":60,"pace_target":null,"zone":4,"intensity":"high"}'], 'finish_reason' => 'stop']],
        ])]);

        (new AdjustPlanForWellbeingJob($user->id, $wellbeing->id))
            ->handle(app(\App\Services\AI\SessionContentService::class), app(GarminHealthSummary::class));

        $this->assertStringContainsString('keine vorhanden', $this->promptSent());
    }

    // ── Die Auswertung selbst ────────────────────────────────────────────

    /** Gemessen wird gegen die eigene Grundlinie, nicht gegen einen Normwert. */
    public function test_the_flags_compare_against_the_athletes_own_baseline(): void
    {
        [$user] = $this->athlete();

        foreach (range(1, 14) as $d) {
            $this->metric($user, $d, 60, 50);
        }
        $this->metric($user, 0, 45, 56, 4.5, 28);

        $day = app(GarminHealthSummary::class)->forDay($user->id, CarbonImmutable::today());

        $this->assertTrue($day['has_data']);
        $this->assertSame(28, $day['readiness']);
        $this->assertGreaterThanOrEqual(3, count($day['flags']), 'HRV, Ruhepuls, Schlaf und Readiness sind alle auffällig');
    }

    /** Dieselben Absolutwerte sind bei anderer Grundlinie unauffällig. */
    public function test_the_same_numbers_can_be_unremarkable(): void
    {
        [$user] = $this->athlete();

        // Dieser Athlet hat von Haus aus eine HRV um 45 und Ruhepuls 56.
        foreach (range(1, 14) as $d) {
            $this->metric($user, $d, 45, 56);
        }
        $this->metric($user, 0, 45, 56, 7.5, 70);

        $day = app(GarminHealthSummary::class)->forDay($user->id, CarbonImmutable::today());

        $this->assertEmpty($day['flags'], 'Ohne Abweichung von der eigenen Grundlinie gibt es nichts zu melden');
    }

    /** Ohne Tageswert bleibt es bei der Selbsteinschätzung. */
    public function test_no_metrics_for_today_means_no_data(): void
    {
        [$user] = $this->athlete();
        $this->metric($user, 3, 60, 50);

        $day = app(GarminHealthSummary::class)->forDay($user->id, CarbonImmutable::today());

        $this->assertFalse($day['has_data']);
    }
}
