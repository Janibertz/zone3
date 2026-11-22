<?php

namespace App\Services\Coach;

use App\Models\CoachChange;
use App\Models\SessionRecommendation;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Bike\BikeWorkouts;
use App\Services\TrainingPlanValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Das eine Schreib-Werkzeug des Coaches (Stufe 2, Konzept „Coach-Chat neu“,
 * 22.11.2026): eine Liste von Schritten als ein Paket.
 *
 * Bis dahin gab es neun Werkzeuge, die Einheiten über Datum und Typ suchten,
 * jedes mit eigenen Regeln. Die zusätzliche Radeinheit traf keines („Rad
 * streichen“ am 22.11.), die Abendhälfte nur mit `part`, und die Bestätigung
 * schrieb das Modell frei. Jetzt:
 *
 * - Einheiten werden über ihre ID angesprochen.
 * - Vier Schritte: `set` (setzen/ersetzen), `add` (zusätzlich), `move`, `remove`.
 * - Alles läuft in einer Transaktion; danach prüft {@see check()} die Tage
 *   (ein Lauf pro Tag, Doppel-Schwelle, Pause). Ein Verstoß verwirft das
 *   ganze Paket mit Grund, eine Probe (`dry_run`) verwirft immer.
 * - Die Bestätigung („Geändert: …“) entsteht hier aus der Datenbank.
 * - Jedes angewendete Paket wird ein {@see CoachChange} mit Vorher-Stand —
 *   „Rückgängig“ stellt ihn wieder her.
 */
class PlanChange
{
    public const MODES = ['dry_run', 'apply', 'propose'];
    public const OPS = ['set', 'add', 'move', 'remove'];
    public const SPORTS = ['run', 'bike', 'strength', 'core', 'mobility', 'rest'];
    public const MAX_STEPS = 8;

    /** Einheiten vor der ersten Änderung, roh (id => Zeile). */
    private array $before = [];
    private ?array $overridesBefore = null;
    private ?int $planId = null;
    /** @var array<string,bool> */
    private array $dates = [];
    private array $lines = [];
    private array $warnings = [];

    public function __construct(private SessionWriter $writer) {}

    /**
     * @return array{ok: bool, mode: string, lines?: array, after?: array, rejected?: array, warnings?: array, change_id?: int, recommendation_id?: int, proposal?: array}
     */
    public function run(User $user, array $steps, string $mode): array
    {
        $this->before = $this->dates = $this->lines = $this->warnings = [];
        $this->overridesBefore = $this->planId = null;

        if (! in_array($mode, self::MODES, true)) {
            return $this->reject($mode, ['mode muss dry_run, apply oder propose sein.']);
        }
        $steps = array_values(array_map([self::class, 'normalize'], array_filter($steps, 'is_array')));
        if (! $steps) {
            return $this->reject($mode, ['Keine Schritte.']);
        }
        if (count($steps) > self::MAX_STEPS) {
            return $this->reject($mode, ['Höchstens ' . self::MAX_STEPS . ' Schritte je Paket.']);
        }

        if ($mode === 'propose') {
            return $this->propose($user, $steps);
        }

        $maxId = (int) TrainingSession::max('id');
        $errors = [];
        $result = null;

        DB::beginTransaction();
        try {
            // Eine Probe löst keine Beobachter aus (Garmin-, COROS-, Zwift-
            // Abgleich): sie wird ohnehin zurückgerollt.
            $runSteps = function () use ($user, $steps, &$errors) {
                foreach ($steps as $i => $step) {
                    if ($error = $this->step($user, $step)) {
                        $errors[] = 'Schritt ' . ($i + 1) . ' (' . ($step['op'] ?? '?') . '): ' . $error;
                        break;
                    }
                }
            };
            $mode === 'dry_run' ? TrainingSession::withoutEvents($runSteps) : $runSteps();
            if (! $errors) {
                $errors = $this->check($user);
            }

            $after = $this->after($user);
            $created = TrainingSession::where('user_id', $user->id)->where('id', '>', $maxId)->pluck('id')->all();

            if ($errors || $mode === 'dry_run') {
                DB::rollBack();
            } else {
                $change = CoachChange::create([
                    'user_id'               => $user->id,
                    'steps'                 => $steps,
                    'lines'                 => $this->lines,
                    'before'                => array_values($this->before),
                    'created_ids'           => array_values(array_diff($created, array_keys($this->before))),
                    'plan_id'               => $this->planId,
                    'plan_overrides_before' => $this->overridesBefore,
                ]);
                DB::commit();
                $result = $change;
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::warning('plan_change fehlgeschlagen', ['user_id' => $user->id, 'error' => $e->getMessage(), 'steps' => $steps]);

            return $this->reject($mode, ['Technischer Fehler beim Schreiben — nichts geändert.']);
        }

        if ($errors) {
            return $this->reject($mode, $errors);
        }

        if ($result) {
            $this->writer->invalidateCoachCaches($user);
        }

        return array_filter([
            'ok'        => true,
            'mode'      => $mode,
            'lines'     => $this->lines,
            'after'     => $after,
            'warnings'  => $this->warnings ?: null,
            'change_id' => $result?->id,
        ], fn ($v) => $v !== null);
    }

    /**
     * Das Modell füllt über die Responses API jedes Feld aus, auch die, die
     * nicht gemeint sind: session_id 0, distance_km 0, part „am“, leere
     * Texte, open_warmup false (Jans Intervalle am 22.11.2026). Leeres und
     * Null zählt als „nicht angegeben“; false bei den offenen Enden auch —
     * sonst nähme jeder Aufruf ein Einlaufen per Lap-Taste still wieder weg.
     */
    public static function normalize(array $step): array
    {
        $out = [];
        foreach ($step as $key => $value) {
            if ($value === null || $value === '' || $value === [] || $value === false) continue;
            if (in_array($key, ['session_id', 'duration_min', 'distance_km'], true) && (float) $value <= 0) continue;
            $out[$key] = $value;
        }
        // part gilt nur für Läufe; bike_workout nur fürs Rad.
        if (($out['sport'] ?? null) !== 'run') unset($out['part']);
        if (($out['sport'] ?? null) !== 'bike') unset($out['bike_workout']);

        return $out;
    }

    private function reject(string $mode, array $reasons): array
    {
        return ['ok' => false, 'mode' => $mode, 'rejected' => $reasons, 'note' => 'Nichts geändert.'];
    }

    // ── Schritte ────────────────────────────────────────────────────────

    /** Führt einen Schritt aus. Gibt einen Fehler zurück oder null. */
    private function step(User $user, array $step): ?string
    {
        return match ($step['op'] ?? null) {
            'set'    => $this->set($user, $step),
            'add'    => $this->add($user, $step),
            'move'   => $this->move($user, $step),
            'remove' => $this->remove($user, $step),
            default  => 'op muss set, add, move oder remove sein.',
        };
    }

    private function set(User $user, array $step): ?string
    {
        $sport = $step['sport'] ?? null;

        if (! empty($step['session_id'])) {
            $session = $this->find($user, $step['session_id']);
            if (is_string($session)) return $session;
            if ($session->status !== 'planned') return "Einheit {$session->id} ist {$this->statusWord($session)} und bleibt, wie sie ist.";
            if ($session->type === 'race_prep') return 'Ein Rennen ändert der Coach nicht.';
            if (! empty($step['date']) && $step['date'] !== $session->planned_date->toDateString()) {
                return 'set ändert eine Einheit an ihrem Tag — zum Verschieben move nehmen.';
            }
            if ($session->planned_date->toDateString() < today()->toDateString()) return 'Der Tag ist vorbei.';
            $sport ??= $this->sportOf($session);
        } else {
            if (! $sport) return 'sport fehlt (run, bike, strength, core, mobility oder rest).';
            $date = $this->date($step['date'] ?? null);
            if (is_null($date)) return 'Ungültiges oder vergangenes Datum.';

            if ($sport === 'rest') {
                return $this->restDay($user, $date, (string) ($step['reason'] ?? ''));
            }

            $candidates = $this->sessionsOn($user, $date)->filter(fn ($s) => $s->status === 'planned' && $this->sportOf($s) === $sport)->values();
            if ($sport === 'run' && $candidates->count() > 1 && in_array($step['part'] ?? null, ['am', 'pm'], true)) {
                $candidates = collect([$step['part'] === 'am' ? $candidates->first() : $candidates->last()]);
            }
            if ($candidates->count() > 1) {
                return "Am {$date} stehen mehrere passende Einheiten: " . $this->listing($candidates) . ' — gib session_id an oder frag nach, welche gemeint ist.';
            }
            if ($sport === 'run' && $this->sessionsOn($user, $date)->contains(fn ($s) => $s->status === 'completed' && $this->sportOf($s) === 'run')) {
                return "Am {$date} ist schon ein Lauf erledigt — ein zweiter Lauf am Tag geht nicht.";
            }

            $session = $candidates->first();
            if (! $session) {
                return $this->create($user, $date, $sport, $step);
            }
        }

        if ($sport === 'rest') {
            return $this->restDay($user, $session->planned_date->toDateString(), (string) ($step['reason'] ?? ''));
        }

        return $this->fill($user, $session, $sport, $step);
    }

    private function add(User $user, array $step): ?string
    {
        $sport = $step['sport'] ?? null;
        if (! in_array($sport, self::SPORTS, true) || $sport === 'rest') {
            return 'add braucht sport run, bike, strength, core oder mobility. Ein Ruhetag ist set mit sport rest.';
        }
        $date = $this->date($step['date'] ?? null);
        if (is_null($date)) return 'Ungültiges oder vergangenes Datum.';

        if ($sport === 'run') {
            $runs = $this->sessionsOn($user, $date)->filter(fn ($s) => in_array($s->status, ['planned', 'completed'], true) && $this->sportOf($s) === 'run');
            $double = $runs->count() === 1 && in_array($step['part'] ?? null, ['am', 'pm'], true)
                && ($step['type'] ?? 'tempo_run') === 'tempo_run' && $runs->first()->type === 'tempo_run'
                && $this->writer->doubleAllowedOn($user, $date);
            if ($runs->isNotEmpty() && ! $double) {
                return "Am {$date} steht schon ein Lauf (" . $this->listing($runs) . '). Pro Tag gibt es einen Lauf — ändere ihn mit set und seiner session_id.';
            }
            if ($double) {
                $existing = $runs->first();
                $this->remember($existing);
                if ($step['part'] === 'am') {
                    $existing->sort_order += 1;
                    $existing->save();
                }
            }
        }

        return $this->create($user, $date, $sport, $step);
    }

    private function move(User $user, array $step): ?string
    {
        $session = $this->find($user, $step['session_id'] ?? null);
        if (is_string($session)) return $session;
        if ($session->status !== 'planned') return "Einheit {$session->id} ist {$this->statusWord($session)} und bleibt, wo sie ist.";
        if (in_array($session->type, ['rest', 'race_prep'], true)) return 'Ruhetage und Rennen verschiebt der Coach nicht.';

        $from = $session->planned_date->toDateString();
        if ($from < today()->toDateString()) return 'Der Tag ist vorbei.';
        $to = $this->date($step['to_date'] ?? null);
        if (is_null($to)) return 'to_date fehlt oder liegt in der Vergangenheit.';
        if ($to === $from) return 'Die Einheit steht schon an dem Tag.';

        // Ein Ruhetag am Zieltag fällt weg — Ruhe und Training zugleich ist keiner.
        foreach ($this->sessionsOn($user, $to)->where('type', 'rest')->where('status', 'planned') as $rest) {
            $this->remember($rest);
            $rest->delete();
        }

        $before = $this->label($session);
        $this->remember($session);
        $session->planned_date = $to;
        $session->sort_order   = (int) TrainingSession::where('user_id', $user->id)->whereDate('planned_date', $to)->max('sort_order') + 1;
        $session->pinned_at    = now();
        $session->save();
        $this->dates[$from] = $this->dates[$to] = true;

        $this->sealVacated($user, $from);
        $this->lines[] = "Verschoben: {$before} von {$this->writer->dayLabel($from)} auf {$this->writer->dayLabel($to)}";

        return null;
    }

    private function remove(User $user, array $step): ?string
    {
        $session = $this->find($user, $step['session_id'] ?? null);
        if (is_string($session)) return $session;
        if ($session->status !== 'planned') return "Einheit {$session->id} ist {$this->statusWord($session)} und bleibt stehen.";
        if ($session->type === 'race_prep') return 'Ein Rennen streicht der Coach nicht.';
        $date = $session->planned_date->toDateString();
        if ($date < today()->toDateString()) return 'Der Tag ist vorbei.';

        $label = $this->label($session);
        $this->remember($session);
        $session->delete();
        $this->dates[$date] = true;

        $this->sealVacated($user, $date);
        $this->lines[] = "Gestrichen ({$this->writer->dayLabel($date)}): {$label}";

        return null;
    }

    // ── Einheiten schreiben ─────────────────────────────────────────────

    private function create(User $user, string $date, string $sport, array $step): ?string
    {
        // Training an einem Ruhetag: der Ruhetag fällt weg.
        foreach ($this->sessionsOn($user, $date)->where('type', 'rest')->where('status', 'planned') as $rest) {
            $this->remember($rest);
            $rest->delete();
        }

        $type = match ($sport) {
            'run'   => $this->runType($step['type'] ?? null) ?? 'easy_run',
            'bike'  => 'cross_training',
            default => $sport,
        };
        $session = $this->writer->newSessionFor($user, $date, $type);
        if ($sport === 'run' && ($step['part'] ?? null) === 'pm') {
            $session->sort_order = (int) TrainingSession::where('user_id', $user->id)->whereDate('planned_date', $date)->max('sort_order') + 1;
        }
        $this->dates[$date] = true;

        $error = $this->fill($user, $session, $sport, $step, created: true);
        if ($error) return $error;

        return null;
    }

    private function fill(User $user, TrainingSession $session, string $sport, array $step, bool $created = false): ?string
    {
        if ($sport === 'run' && $this->looksLikeBike($step)) {
            return 'Das klingt nach Rad/Zwift — dafür sport bike nehmen, nicht run.';
        }

        $date   = $session->planned_date instanceof \DateTimeInterface ? $session->planned_date->format('Y-m-d') : (string) $session->planned_date;
        $before = $created ? null : $this->label($session);
        $wasRun = ! $created && $this->sportOf($session) === 'run';
        if (! $created) {
            $this->remember($session);
        }
        $this->dates[$date] = true;

        if ($sport === 'bike') {
            $kind = in_array($step['bike_workout'] ?? null, [BikeWorkouts::EASY, BikeWorkouts::VO2, BikeWorkouts::THRESHOLD], true) ? $step['bike_workout'] : null;
            if ($wasRun && ! $kind) {
                $kind = match ($session->type) {
                    'easy_run', 'progressive_run' => BikeWorkouts::EASY,
                    'interval'                   => BikeWorkouts::VO2,
                    default                      => null,
                };
                if (! $kind) {
                    return "„{$session->title}“ ({$session->type}) bleibt ein Lauf: aufs Rad wandern nur lockere Läufe und Intervalle (Jans Regel). Langer Lauf, Schwelle und Rennen trainieren das Laufen selbst.";
                }
            }
            $kind ??= BikeWorkouts::EASY;
            $minutes = (int) ($step['duration_min'] ?? 0);
            if ($minutes <= 0) {
                $minutes = $wasRun && $kind === BikeWorkouts::EASY
                    ? app(BikeWorkouts::class)->easyMinutesFor((int) ($session->duration_min ?: 45))
                    : (int) ($session->duration_min ?: 45);
            }
            $this->writer->fillBikeSession($session, $kind, $minutes, $user->runnerProfile?->ftp);
        } else {
            $args = array_intersect_key($step, array_flip([
                'title', 'description', 'distance_km', 'duration_min', 'workout',
                'easy_pace', 'hard_pace', 'open_warmup', 'open_cooldown', 'exercises',
            ]));
            $args['type'] = match ($sport) {
                'run'   => $this->runType($step['type'] ?? null) ?? ($this->sportOf($session) === 'run' ? $session->type : 'easy_run'),
                default => $sport,
            };
            $session->sport_type = null;
            if (! $created && $session->type === 'cross_training') {
                // Vom Rad zurück aufs Laufen: Titel und Zahlen gehörten zum Rad.
                $session->title = '';
                $session->description = '';
                $session->distance_km = null;
                $session->duration_min = $args['duration_min'] ?? null;
                $session->steps = null;
                $session->intensity = '';
            }
            if (! $created && $args['type'] !== $session->type && ! isset($args['title'])) {
                $session->title = '';
            }
            $this->writer->applySessionFields($session, $args, $user);
            $session->save();
        }

        $session->refresh();
        $this->lines[] = $created
            ? "Neu ({$this->writer->dayLabel($date)}): {$this->label($session)}"
            : "Geändert ({$this->writer->dayLabel($date)}): {$before} → {$this->label($session)}";

        return null;
    }

    /** Ruhetag an einem Tag — wie das alte set_rest_day, aber im Paket. */
    private function restDay(User $user, string $date, string $reason): ?string
    {
        $sessions = $this->sessionsOn($user, $date);
        $struck = [];
        foreach ($sessions as $s) {
            if ($s->status === 'planned' && $s->type !== 'rest') {
                $this->remember($s);
                $struck[] = $this->label($s);
                $s->status = 'skipped';
                $s->save();
            }
        }

        SessionRecommendation::where('user_id', $user->id)
            ->where('status', SessionRecommendation::STATUS_PENDING)
            ->whereHas('session', fn ($q) => $q->whereDate('planned_date', $date))
            ->update(['status' => SessionRecommendation::STATUS_EXPIRED]);

        $rest = $sessions->first(fn ($s) => $s->type === 'rest');
        if ($rest) {
            $this->remember($rest);
        } else {
            $rest = $this->writer->newSessionFor($user, $date, 'rest');
            $rest->sort_order = 0;
        }
        $reason = trim($reason);
        $rest->fill([
            'status'       => 'planned',
            'title'        => 'Ruhetag',
            'description'  => $reason !== '' ? "Ruhetag auf deinen Wunsch ({$reason})." : 'Ruhetag auf deinen Wunsch.',
            'distance_km'  => null,
            'duration_min' => null,
            'pace_target'  => null,
            'zone'         => null,
            'steps'        => null,
            'pinned_at'    => now(),
        ])->save();

        // Sonst zeigt „Heute“ ohne Planeinheit weiter einen Tagesvorschlag.
        if ($plan = $user->activeTrainingPlan()->first()) {
            if ($this->planId === null) {
                $this->planId = $plan->id;
                $this->overridesBefore = $plan->availability_overrides;
            }
            $overrides = $plan->availability_overrides ?? [];
            $overrides[$date] = array_merge($overrides[$date] ?? [], ['available' => false]);
            $plan->availability_overrides = $overrides;
            $plan->save();
        }

        $this->dates[$date] = true;
        $this->lines[] = "Ruhetag ({$this->writer->dayLabel($date)})" . ($struck ? ': gestrichen ' . implode(', ', $struck) : '');

        return null;
    }

    private function propose(User $user, array $steps): array
    {
        $step = $steps[0];
        if (count($steps) !== 1 || ($step['op'] ?? null) !== 'set' || empty($step['session_id'])) {
            return $this->reject('propose', ['Ein Vorschlag ist genau ein set-Schritt mit session_id. Verschieben oder Streichen aus eigener Idee: im Text nachfragen.']);
        }
        $session = $this->find($user, $step['session_id']);
        if (is_string($session)) return $this->reject('propose', [$session]);
        if ($session->status !== 'planned') return $this->reject('propose', ["Einheit {$session->id} ist {$this->statusWord($session)}."]);
        if ($session->planned_date->toDateString() < today()->toDateString()) return $this->reject('propose', ['Der Tag ist vorbei.']);
        $sport = $step['sport'] ?? $this->sportOf($session);
        if ($sport !== $this->sportOf($session) || ! in_array($sport, ['run', 'strength', 'core', 'mobility', 'rest'], true)) {
            return $this->reject('propose', ['Ein Vorschlag ändert eine Lauf- oder Krafteinheit ohne Sportwechsel. Rad statt Lauf: im Text fragen und nach dem Ja mit apply umsetzen.']);
        }

        $args = array_intersect_key($step, array_flip([
            'title', 'description', 'distance_km', 'duration_min', 'workout',
            'easy_pace', 'hard_pace', 'open_warmup', 'open_cooldown', 'exercises',
        ]));
        $args['type'] = $sport === 'run' ? ($this->runType($step['type'] ?? null) ?? $session->type) : $sport;
        if ($sport === 'rest') {
            $args += ['title' => 'Ruhetag', 'distance_km' => null, 'duration_min' => null];
        }

        return ['ok' => true, 'mode' => 'propose', 'proposal' => ['session' => $session, 'args' => $args, 'reason' => (string) ($step['reason'] ?? '')]];
    }

    // ── Prüfung ─────────────────────────────────────────────────────────

    /**
     * Dieselben Regeln wie Plan-Validator und Gerüst, auf die berührten Tage:
     * ein Lauf pro Tag (Doppel-Schwelle nur, wo das Gerüst sie legen würde),
     * kein Lauf in einer Trainingspause.
     */
    private function check(User $user): array
    {
        $errors = [];
        $profile = $user->runnerProfile;

        foreach (array_keys($this->dates) as $date) {
            $runs = $this->sessionsOn($user, $date)->filter(fn ($s) => in_array($s->status, ['planned', 'completed'], true) && $this->sportOf($s) === 'run');

            if ($runs->count() > 1) {
                $double = $runs->count() === 2 && $runs->every(fn ($s) => $s->type === 'tempo_run') && $this->writer->doubleAllowedOn($user, $date);
                if (! $double) {
                    $errors[] = "Am {$date} stünden danach zwei Läufe (" . $this->listing($runs) . '). Pro Tag ein Lauf — einen davon mit remove streichen oder set auf die bestehende Einheit.';
                }
            }

            if ($runs->contains('status', 'planned') && $profile?->training_paused_until && $date <= $profile->training_paused_until->toDateString()) {
                $errors[] = "Am {$date} läuft die Trainingspause (bis {$profile->training_paused_until->format('d.m.')}). Erst die Pause beenden (set_pause) oder fragen.";
            }
        }

        return $errors;
    }

    // ── Hilfen ──────────────────────────────────────────────────────────

    /** Die Einheit des Athleten oder ein Fehlertext. */
    private function find(User $user, mixed $id): TrainingSession|string
    {
        if (! $id) return 'session_id fehlt.';
        $session = TrainingSession::where('user_id', $user->id)->find((int) $id);

        return $session ?? "Einheit {$id} gibt es nicht (mehr). Mit get_plan nachsehen.";
    }

    /** Vorher-Stand einmal merken, bevor die Einheit geändert oder gelöscht wird. */
    private function remember(TrainingSession $session): void
    {
        if (! $session->exists || isset($this->before[$session->id])) return;
        $row = DB::table('training_sessions')->where('id', $session->id)->first();
        $this->before[$session->id] = $row ? (array) $row : $session->getAttributes();
    }

    private function sealVacated(User $user, string $date): void
    {
        app(\App\Services\SessionScheduleService::class)->sealVacatedDay($user, $date, 'vom Coach geändert');
    }

    private function sessionsOn(User $user, string $date): Collection
    {
        return TrainingSession::where('user_id', $user->id)
            ->whereDate('planned_date', $date)
            ->where('status', '!=', 'skipped')
            ->orderBy('sort_order')->orderBy('id')
            ->get();
    }

    private function date(?string $date): ?string
    {
        if (! $date) return today()->toDateString();
        try {
            $parsed = \Carbon\CarbonImmutable::parse($date)->toDateString();
        } catch (\Throwable) {
            return null;
        }

        return $parsed < today()->toDateString() ? null : $parsed;
    }

    public function sportOf(TrainingSession $s): string
    {
        if ($s->type === 'cross_training' && in_array($s->sport_type, TrainingSession::BIKE_SPORTS, true)) return 'bike';
        if (in_array($s->type, TrainingPlanValidator::RUN_TYPES, true)) return 'run';

        return $s->type;
    }

    private function runType(?string $type): ?string
    {
        return in_array($type, TrainingPlanValidator::RUN_TYPES, true) && $type !== 'race_prep' ? $type : null;
    }

    private function looksLikeBike(array $step): bool
    {
        $text = mb_strtolower(($step['title'] ?? '') . ' ' . ($step['description'] ?? ''));

        return (bool) preg_match('/\b(zwift|rouvy|radeinheit|radfahren|fahrrad|bike|cycling)\b/u', $text);
    }

    private function statusWord(TrainingSession $s): string
    {
        return match ($s->status) { 'completed' => 'schon erledigt', 'skipped' => 'ausgelassen', default => $s->status };
    }

    /** „Lockerer Lauf 45 min“, „Rad locker 56 min“ — für Bestätigung und Listen. */
    public function label(TrainingSession $s): string
    {
        $bits = [$s->title ?: $this->writer->sessionTitleFor($s->type)];
        if ($s->type !== 'rest') {
            if ($s->distance_km && $this->sportOf($s) === 'run') $bits[] = rtrim(rtrim(number_format((float) $s->distance_km, 1, ',', ''), '0'), ',') . ' km';
            if ($s->duration_min) $bits[] = (int) $s->duration_min . ' min';
        }

        return implode(' ', $bits);
    }

    private function listing(Collection $sessions): string
    {
        return $sessions->map(fn ($s) => "[ID:{$s->id}] {$this->label($s)}" . ($s->status === 'completed' ? ' (erledigt)' : ''))->implode(', ');
    }

    /** Die berührten Tage danach, je Einheit mit ID und Ablauf. */
    private function after(User $user): array
    {
        $out = [];
        foreach (array_keys($this->dates) as $date) {
            $out[$date] = $this->sessionsOn($user, $date)->map(fn ($s) => array_filter([
                'id'     => $s->id,
                'status' => $s->status,
                'sport'  => $this->sportOf($s),
                'type'   => $s->type,
                'label'  => $this->label($s),
                'steps'  => trim(preg_replace('/\s*Bestätige dem Athleten.*$/u', '', $this->writer->stepsLine($s))) ?: null,
            ]))->values()->all();
        }
        ksort($out);

        return $out;
    }
}
