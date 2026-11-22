<?php

namespace Tests\CoachEval;

use App\Jobs\CoachReplyJob;
use App\Models\AppSetting;
use App\Models\CoachMessage;
use App\Models\CoachTurn;
use App\Models\Event;
use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\AI\CoachChatService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Evals: der Coach gegen das ECHTE Modell (Konzept „Coach-Chat neu“, 22.11.2026).
 *
 * Die Tests unter tests/Feature fälschen die Antwort des Modells und prüfen,
 * ob ein Werkzeug richtig schreibt. Ob das Modell das richtige Werkzeug
 * wählt, sehen sie nie — und genau dort entstanden die Fehler. Hier schreibt
 * jedes Szenario einen erfundenen Athleten in eine frische Datenbank, schickt
 * seine Nachricht durch CoachReplyJob an OpenAI und prüft danach den Zustand
 * der Datenbank, nicht den Wortlaut.
 *
 * Kostet Geld (ca. 1 Cent je Szenario und Durchlauf) und liegt deshalb nicht
 * in einer Test-Suite: `composer test` läuft es nie.
 *
 *   COACH_EVAL_OPENAI_KEY=sk-… php artisan test tests/CoachEval
 *   COACH_EVAL_REPEAT=3      wie oft jedes Szenario läuft (Vorgabe 3, bestanden = alle)
 *   COACH_EVAL_ONLY=rad      nur Szenarien, deren Dateiname das enthält
 *
 * Ein neues Szenario ist eine Datei in scenarios/ — Regel: kein Fix am Coach
 * ohne neues Szenario.
 */
class CoachEvalTest extends TestCase
{
    use RefreshDatabase;

    /** Ein Montag: „Sonntag“ ist dann eindeutig der 29.11. */
    public const NOW = '2026-11-23 09:00:00';

    /** @var array<string, array{passed:int, runs:int, errors:list<string>, cost:float}> */
    private static array $report = [];

    protected function setUp(): void
    {
        parent::setUp();

        $key = env('COACH_EVAL_OPENAI_KEY');
        if (! $key) {
            $this->markTestSkipped('COACH_EVAL_OPENAI_KEY fehlt — Evals laufen nur mit eigenem Schlüssel.');
        }

        // Nur OpenAI, sonst nichts (kein Push, kein Telegram, keine Uhr).
        Http::allowStrayRequests(['https://api.openai.com/*']);
        config([
            'services.openai.api_key' => $key,
            'services.telegram.bot_token' => null,
        ]);
        AppSetting::set('coach_engine', env('COACH_EVAL_ENGINE', 'responses'));
        Carbon::setTestNow(self::NOW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function scenarios(): iterable
    {
        $only   = (string) env('COACH_EVAL_ONLY', '');
        $repeat = max(1, (int) env('COACH_EVAL_REPEAT', 3));

        foreach (glob(__DIR__ . '/scenarios/*.php') as $file) {
            $name = basename($file, '.php');
            if ($only !== '' && ! str_contains($name, $only)) {
                continue;
            }
            for ($i = 1; $i <= $repeat; $i++) {
                yield "{$name} #{$i}" => [$name, $file];
            }
        }
    }

    #[DataProvider('scenarios')]
    public function test_scenario(string $name, string $file): void
    {
        /** @var array{setup: \Closure, message: string|\Closure, expect: \Closure} $scenario */
        $scenario = require $file;

        $ctx  = ($scenario['setup'])($this);
        $user = $ctx['user'];

        $question = is_callable($scenario['message'])
            ? ($scenario['message'])($ctx)
            : CoachMessage::create(['user_id' => $user->id, 'role' => 'user', 'content' => $scenario['message'], 'channel' => 'telegram']);

        (new CoachReplyJob($question->id))->handle(app(CoachChatService::class));

        $reply = CoachMessage::where('reply_to_id', $question->id)->first();
        $turn  = CoachTurn::where('question_id', $question->id)->first();
        $ctx['reply'] = $reply;
        $ctx['turn']  = $turn;

        self::$report[$name] ??= ['passed' => 0, 'runs' => 0, 'errors' => [], 'cost' => 0.0];
        self::$report[$name]['runs']++;
        self::$report[$name]['cost'] += (float) ($turn?->cost_eur ?? 0);

        try {
            // Für jedes Szenario: eine echte Antwort, keine Rückfallzeile,
            // keine Überlegung des Modells, kein Werkzeugname im Text.
            $this->assertNotNull($reply, 'keine Antwort');
            $this->assertFalse((bool) $turn?->fallback, 'Rückfallantwort statt Antwort');
            $this->assertFalse(CoachChatService::looksLikeLeakedReasoning($reply->content), 'Überlegung statt Antwort: ' . $reply->content);
            $this->assertDoesNotMatchRegularExpression('/\b[a-z]+_[a-z_]+\(|\b(set_rest_day|modify_training_session|delete_training_session)\b/', $reply->content, 'Werkzeugname im Text');

            ($scenario['expect'])($this, $ctx);
            self::$report[$name]['passed']++;
        } catch (\Throwable $e) {
            self::$report[$name]['errors'][] = mb_substr($e->getMessage(), 0, 300) . ' | Antwort: ' . mb_substr((string) $reply?->content, 0, 300);
            throw $e;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$report) {
            @mkdir(storage_path('app/coach-eval'), 0775, true);
            $lines = ["| Szenario | bestanden | Kosten |", "| --- | --- | --- |"];
            foreach (self::$report as $name => $r) {
                $lines[] = sprintf('| %s | %d von %d | %.3f € |', $name, $r['passed'], $r['runs'], $r['cost']);
            }
            foreach (self::$report as $name => $r) {
                foreach ($r['errors'] as $err) {
                    $lines[] = "\n**{$name}:** " . $err;
                }
            }
            file_put_contents(storage_path('app/coach-eval/report.md'), implode("\n", $lines) . "\n");
            file_put_contents(storage_path('app/coach-eval/report.json'), json_encode(self::$report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        parent::tearDownAfterClass();
    }

    // ---- Bausteine für die Szenarien -------------------------------------

    /** Ein Athlet mit HM-Ziel, laufendem Plan und VDOT (Schwelle 4:30). */
    public function athlete(array $profile = [], array $event = []): array
    {
        $user = User::factory()->onboarded()->create(['name' => 'Testläufer']);
        $user->forceFill(['ai_coach_consent_at' => now()])->save();
        $event = Event::create(array_merge([
            'user_id' => $user->id, 'name' => 'Halbmarathon', 'event_date' => '2027-04-11',
            'race_distance' => 'half_marathon', 'priority' => 'A',
        ], $event));
        $plan = TrainingPlan::create(['user_id' => $user->id, 'event_id' => $event->id, 'sessions' => [], 'is_active' => true]);
        RunnerProfile::create(array_merge(['user_id' => $user->id, 'threshold_speed' => 4.5], $profile));

        return ['user' => $user->fresh(), 'event' => $event, 'plan' => $plan];
    }

    public function addSession(array $ctx, string $date, string $type, array $extra = []): TrainingSession
    {
        return TrainingSession::create(array_merge([
            'user_id' => $ctx['user']->id, 'training_plan_id' => $ctx['plan']->id, 'event_id' => $ctx['event']->id,
            'planned_date' => $date, 'type' => $type, 'title' => match ($type) {
                'easy_run' => 'Lockerer Lauf', 'long_run' => 'Langer Lauf', 'interval' => '5×1000 m',
                'tempo_run' => 'Tempolauf', 'rest' => 'Ruhetag', 'strength' => 'Kraft', default => ucfirst($type),
            },
            'description' => '', 'intensity' => in_array($type, ['interval', 'tempo_run'], true) ? 'high' : 'low',
            'status' => 'planned', 'sort_order' => 1,
            'duration_min' => match ($type) { 'long_run' => 100, 'rest' => null, default => 45 },
            'distance_km'  => match ($type) { 'long_run' => 18, 'rest' => null, 'strength' => null, default => 8 },
        ], $extra));
    }

    /** Eine normale Woche ab heute (Montag): Mo locker, Mi Intervall, Do locker, Sa Ruhe, So lang. */
    public function week(array $ctx): array
    {
        return [
            'mon' => $this->addSession($ctx, '2026-11-23', 'easy_run'),
            'tue' => $this->addSession($ctx, '2026-11-24', 'rest'),
            'wed' => $this->addSession($ctx, '2026-11-25', 'interval'),
            'thu' => $this->addSession($ctx, '2026-11-26', 'easy_run'),
            'fri' => $this->addSession($ctx, '2026-11-27', 'rest'),
            'sat' => $this->addSession($ctx, '2026-11-28', 'rest'),
            'sun' => $this->addSession($ctx, '2026-11-29', 'long_run'),
        ];
    }

    /** Geplante bzw. nicht gestrichene Einheiten eines Tages. */
    public function activeOn(User $user, string $date)
    {
        return TrainingSession::where('user_id', $user->id)->whereDate('planned_date', $date)
            ->whereIn('status', ['planned', 'completed'])->get();
    }
}
