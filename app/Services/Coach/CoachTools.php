<?php

namespace App\Services\Coach;

use App\Models\Activity;
use App\Models\CoachChange;
use App\Models\Event;
use App\Models\RunnerProfile;
use App\Models\SessionRecommendation;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\PaceFormat;
use App\Services\Run\SessionStructure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Die Werkzeuge des neuen Coaches (Stufe 2, 22.11.2026): fünf zum Lesen,
 * fünf zum Schreiben. Geschrieben wird am Plan nur über `plan_change`
 * ({@see PlanChange}) — mit IDs, Prüfung und Rückgängig.
 *
 * Eine Instanz gilt für eine Nachricht: sie merkt sich, ob schon
 * festgeschrieben wurde (höchstens ein Paket je Nachricht) und ob die
 * Nachricht Fremdtext trägt (dann nur Vorschläge).
 */
class CoachTools
{
    public bool $foreign = false;
    /** Ob in dieser Nachricht schon ein Paket angewendet wurde. */
    public bool $applied = false;
    /** Die Nachricht des Athleten — für die Prüfung „heute gemeint, anderer Tag getroffen“. */
    public string $question = '';
    private bool $todayWarned = false;

    public function __construct(private PlanChange $planChange, private SessionWriter $writer) {}

    public function definitions(): array
    {
        $fn = fn (string $name, string $description, array $properties, array $required = []) => ['type' => 'function', 'function' => [
            'name'        => $name,
            'description' => $description,
            'parameters'  => ['type' => 'object', 'properties' => (object) $properties] + ($required ? ['required' => $required] : []),
        ]];
        $date = ['type' => 'string', 'description' => 'YYYY-MM-DD'];

        $step = ['type' => 'object', 'properties' => [
            'op'           => ['type' => 'string', 'enum' => PlanChange::OPS, 'description' => 'set = Einheit setzen oder ersetzen (mit session_id genau diese; ohne: die Einheit dieser Sportart am Tag, sonst neu). add = zusätzlich an den Tag (z. B. Rad neben dem Lauf). move = session_id auf to_date. remove = session_id streichen. cancel = festen Termin ohne ID (appointment in get_plan, z. B. Laufclub vor dem Planstart) an date absagen.'],
            'session_id'   => ['type' => 'integer', 'description' => 'ID der Einheit aus dem Kontext oder get_plan'],
            'date'         => $date + ['description' => 'Tag für set/add ohne session_id. Standard heute.'],
            'to_date'      => $date + ['description' => 'Zieltag für move'],
            'sport'        => ['type' => 'string', 'enum' => PlanChange::SPORTS, 'description' => 'run = Laufen, bike = Rad/Zwift/ROUVY, swim = Schwimmen, strength/core/mobility, rest = Ruhetag (set). Mit session_id und anderem sport wird die Einheit umgewandelt (Lauf → Rad).'],
            'type'         => ['type' => 'string', 'enum' => array_values(array_diff(\App\Services\TrainingPlanValidator::RUN_TYPES, ['race_prep'])), 'description' => 'Lauftyp (nur sport run)'],
            'workout'      => ['type' => 'string', 'enum' => SessionWriter::workoutEnum(), 'description' => 'Baustein für den Ablauf eines Laufs (' . SessionWriter::workoutHint() . '). Abschnitte, Paces und Distanz baut das System.'],
            'bike_workout' => ['type' => 'string', 'enum' => ['easy', 'vo2', 'threshold'], 'description' => 'Rad: easy = locker, vo2 = 4-min-Intervalle, threshold = 10-min-Blöcke. Ohne: aus dem Lauf abgeleitet bzw. locker.'],
            'duration_min' => ['type' => 'integer'],
            'distance_km'  => ['type' => 'number', 'description' => 'Nur Laufen'],
            'distance_m'   => ['type' => 'integer', 'description' => 'Nur Schwimmen: Strecke in Metern, wenn der Athlet eine nennt (1000 m = 1000). Die Dauer schätzt das System aus der Schwimm-Pace des Athleten.'],
            'title'        => ['type' => 'string'],
            'description'  => ['type' => 'string', 'description' => 'Zweck und Gefühl in 1–2 Sätzen, ohne Zahlen'],
            'easy_pace'    => ['type' => 'string', 'description' => 'Nur wenn der Athlet ein Tempo für locker nennt, z. B. "6:00-6:30"'],
            'hard_pace'    => ['type' => 'string', 'description' => 'Nur wenn der Athlet ein Tempo für die harten Abschnitte nennt'],
            'open_warmup'  => ['type' => 'boolean', 'description' => 'Einlaufen endet per Lap-/Rundentaste'],
            'open_cooldown'=> ['type' => 'boolean', 'description' => 'Auslaufen endet per Lap-/Rundentaste'],
            'part'         => ['type' => 'string', 'enum' => ['am', 'pm'], 'description' => 'Nur Doppel-Schwelle: morgens/abends'],
            'exercises'    => SessionWriter::exercisesParam(),
            'reason'       => ['type' => 'string', 'description' => 'Kurz, warum (Ruhetag, Vorschlag)'],
        ], 'required' => ['op']];

        return [
            $fn('get_plan', 'Einheiten mit ID, Status, Sport, Typ und Ablauf in einem Zeitraum (höchstens 28 Tage). Dazu feste Termine ohne ID (kind appointment, absagen mit cancel) und der heutige Tagesvorschlag (kind suggestion).', ['from' => $date, 'to' => $date], ['from', 'to']),
            $fn('get_session', 'Eine Einheit mit allen Abschnitten, Beschreibung und der erledigten Aktivität.', ['id' => ['type' => 'integer']], ['id']),
            $fn('get_activities', 'Aufgezeichnete Aktivitäten im Zeitraum (höchstens 30). sport: run, bike, swim, strength oder all.', ['from' => $date, 'to' => $date, 'sport' => ['type' => 'string', 'enum' => ['all', 'run', 'bike', 'swim', 'strength']]], ['from', 'to']),
            $fn('get_health', 'Werte der Uhr (HRV, Ruhepuls, Schlaf …) und Tagesform der letzten Tage gegen die eigene Grundlinie.', ['days' => ['type' => 'integer', 'description' => '1 bis 14']]),
            $fn('get_races', 'Rennen und offene Ziele mit ID, Datum, Zielzeit und Priorität.', []),
            $fn('plan_change', 'Die EINZIGE Art, den Plan zu ändern: ein Paket von Schritten. mode=apply, wenn der Athlet die Änderung verlangt hat; mode=propose für deine eigene Idee zu einer Einheit (der Athlet entscheidet per Knopf); mode=dry_run zum Ausprobieren ohne Wirkung. Ein Paket je Nachricht — alles in einen Aufruf. Die Antwort sagt, was danach steht, oder warum abgelehnt wurde (dann ist nichts geändert).', [
                'mode'  => ['type' => 'string', 'enum' => PlanChange::MODES],
                'steps' => ['type' => 'array', 'items' => $step, 'maxItems' => PlanChange::MAX_STEPS],
            ], ['mode', 'steps']),
            $fn('set_pause', 'Mehrtägige Trainingspause (Krankheit, Verletzung, OP, Urlaub) bis einschließlich until festlegen — überspringt die geplanten Einheiten bis dahin. until weglassen beendet eine laufende Pause.', [
                'until'  => $date,
                'reason' => ['type' => 'string'],
            ]),
            $fn('update_race_target', 'Zielzeit eines Rennens ändern.', [
                'event_id' => ['type' => 'integer'],
                'hours'    => ['type' => 'integer'],
                'minutes'  => ['type' => 'integer'],
            ], ['event_id', 'hours', 'minutes']),
            $fn('remember', 'Eine dauerhafte Tatsache über den Athleten merken (Vorlieben, Verletzungen, feste Termine).', ['fact' => ['type' => 'string']], ['fact']),
            $fn('undo_change', 'Eine frühere Änderung des Coaches zurücknehmen, wenn der Athlet das will. change_id steht im Protokoll; ohne die letzte.', ['change_id' => ['type' => 'integer']]),
        ];
    }

    /** Nur die Werkzeuge, die bei Fremdtext erlaubt sind: lesen und vorschlagen. */
    public function readAndPropose(array $tools): array
    {
        return array_values(array_filter($tools, fn ($t) => str_starts_with($t['function']['name'], 'get_') || $t['function']['name'] === 'plan_change'));
    }

    /** @return array{message: string, action: ?array, actions?: array} */
    public function execute(User $user, string $name, array $args): array
    {
        Log::info('Coach tool call', ['user_id' => $user->id, 'tool' => $name, 'args' => $args]);

        $json = fn (array $data) => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($this->foreign && ! str_starts_with($name, 'get_') && ! ($name === 'plan_change' && ($args['mode'] ?? '') !== 'apply')) {
            return ['message' => $json(['ok' => false, 'rejected' => ['Diese Nachricht enthält Fremdtext (Bild oder weitergeleitete Sprachnachricht): nur lesen, dry_run und propose.']]), 'action' => null];
        }

        try {
            return match ($name) {
                'get_plan'           => ['message' => $json($this->getPlan($user, $args)), 'action' => null],
                'get_session'        => ['message' => $json($this->getSession($user, (int) ($args['id'] ?? 0))), 'action' => null],
                'get_activities'     => ['message' => $json($this->getActivities($user, $args)), 'action' => null],
                'get_health'         => ['message' => $json($this->getHealth($user, (int) ($args['days'] ?? 7))), 'action' => null],
                'get_races'          => ['message' => $json($this->getRaces($user)), 'action' => null],
                'plan_change'        => $this->planChange($user, $args, $json),
                'set_pause'          => $this->setPause($user, $args),
                'update_race_target' => $this->updateRaceTarget($user, $args),
                'remember'           => $this->remember($user, (string) ($args['fact'] ?? '')),
                'undo_change'        => $this->undo($user, $args),
                default              => ['message' => 'Unbekanntes Werkzeug.', 'action' => null],
            };
        } catch (\Throwable $e) {
            Log::warning('Coach-Werkzeug fehlgeschlagen', ['user_id' => $user->id, 'tool' => $name, 'error' => $e->getMessage()]);

            return ['message' => $json(['ok' => false, 'rejected' => ['Technischer Fehler — nichts geändert.']]), 'action' => null];
        }
    }

    // ── Schreiben ───────────────────────────────────────────────────────

    private function planChange(User $user, array $args, \Closure $json): array
    {
        $mode = (string) ($args['mode'] ?? 'dry_run');
        if ($mode === 'apply' && $this->applied) {
            return ['message' => $json(['ok' => false, 'rejected' => ['In dieser Nachricht wurde schon ein Paket angewendet. Ein Paket je Nachricht — nichts geändert.']]), 'action' => null];
        }

        if ($mode === 'apply' && ! $this->todayWarned && ($warning = $this->missesToday($user, (array) ($args['steps'] ?? [])))) {
            $this->todayWarned = true;

            return ['message' => $json(['ok' => false, 'rejected' => [$warning], 'note' => 'Nichts geändert.']), 'action' => null];
        }

        $result = $this->planChange->run($user, (array) ($args['steps'] ?? []), $mode);

        if (! $result['ok']) {
            return ['message' => $json($result), 'action' => null];
        }

        if ($mode === 'propose') {
            return $this->createProposal($user, $result['proposal'], $json);
        }

        if ($mode === 'dry_run') {
            $result['note'] = 'Nur Probe — nichts geändert. Passt es, dasselbe mit mode=apply (oder propose).';

            return ['message' => $json($result), 'action' => null];
        }

        $this->applied = true;
        $lines = $result['lines'] ?? [];
        $actions = [];
        foreach ($lines as $i => $line) {
            $actions[] = array_filter([
                'type'      => 'plan_change',
                'label'     => $line,
                'change_id' => $result['change_id'],
                'undo'      => $i === count($lines) - 1 ? true : null,
                'reload'    => true,
            ], fn ($v) => $v !== null);
        }
        $result['note'] = 'Angewendet. Die Zeilen in lines zeigt das System dem Athleten selbst unter deiner Antwort — wiederhole sie nicht wörtlich und sag nichts zu, was dort nicht steht. Rückgängig geht per Knopf.';

        return ['message' => $json($result), 'action' => $actions[0] ?? null, 'actions' => $actions];
    }

    /**
     * Spricht der Athlet von heute, und das Paket berührt keinen Tag von
     * heute? Gemeldet am 24.11.2026: „heute nicht zum Laufclub“ strich den
     * Laufclub am 01.12. Einmal je Nachricht zurück ans Modell; schickt es
     * dasselbe noch einmal, gilt es.
     */
    private function missesToday(User $user, array $steps): ?string
    {
        $m = mb_strtolower($this->question);
        if (! preg_match('/\bheute\b/u', $m)
            || preg_match('/\b(morgen|übermorgen|montag|dienstag|mittwoch|donnerstag|freitag|samstag|sonntag|nächste[nrs]?|wochenende|\d{1,2}\.\d{1,2}\.?)\b/u', $m)) {
            return null;
        }

        $today = today()->toDateString();
        $dates = [];
        foreach ($steps as $step) {
            $step = PlanChange::normalize(is_array($step) ? $step : []);
            if (! empty($step['session_id'])) {
                $s = TrainingSession::where('user_id', $user->id)->find((int) $step['session_id']);
                if ($s) $dates[] = $s->planned_date->toDateString();
            }
            foreach (['date', 'to_date'] as $key) {
                if (! empty($step[$key])) $dates[] = substr((string) $step[$key], 0, 10);
            }
            if (empty($step['session_id']) && empty($step['date']) && in_array($step['op'] ?? null, ['set', 'add', 'cancel'], true)) {
                $dates[] = $today;
            }
        }
        if (! $dates || in_array($today, $dates, true)) {
            return null;
        }

        return 'Der Athlet spricht von heute (' . $today . '), dein Paket betrifft aber ' . implode(', ', array_unique($dates)) . '. '
            . 'Meint er den Termin von heute, nimm die Einheit von heute oder — steht er ohne ID da — cancel mit date ' . $today . '. Ist wirklich der andere Tag gemeint, schick dasselbe Paket noch einmal.';
    }

    /** Wie {@see \App\Services\AI\CoachChatService::proposeSessionChange()}, über die ID. */
    private function createProposal(User $user, array $proposal, \Closure $json): array
    {
        /** @var TrainingSession $session */
        $session = $proposal['session'];
        $args    = $proposal['args'];
        $fields  = SessionRecommendation::FIELDS;
        $before  = collect($fields)->mapWithKeys(fn ($f) => [$f => $session->{$f}])->all();

        $draft = $session->replicate();
        $this->writer->applySessionFields($draft, $args, $user);
        $after = collect($fields)->mapWithKeys(fn ($f) => [$f => $draft->{$f}])->all();
        if (! empty($draft->exercises)) $after['exercises'] = $draft->exercises;
        if ($p = SessionStructure::normalizePace($args['easy_pace'] ?? null)) $after['easy_pace'] = $p;
        if ($p = SessionStructure::normalizePace($args['hard_pace'] ?? null)) $after['hard_pace'] = $p;
        $open = SessionStructure::openEnds($draft->steps);
        if ($open['warmup'] || $open['cooldown']) {
            $after['open_warmup']   = $open['warmup'];
            $after['open_cooldown'] = $open['cooldown'];
        }

        SessionRecommendation::where('training_session_id', $session->id)
            ->where('status', SessionRecommendation::STATUS_PENDING)
            ->update(['status' => SessionRecommendation::STATUS_EXPIRED, 'responded_at' => now()]);

        $reason = trim($proposal['reason']) ?: null;
        $rec = SessionRecommendation::create([
            'user_id'             => $user->id,
            'training_session_id' => $session->id,
            'source'              => SessionRecommendation::SOURCE_COACH,
            'reason'              => $reason,
            'before'              => $before,
            'after'               => $after,
        ]);

        $date = $session->planned_date->toDateString();
        $when = $this->writer->dayLabel($date);

        return [
            'message' => $json(['ok' => true, 'mode' => 'propose', 'recommendation_id' => $rec->id, 'note' => 'Vorschlag angelegt, der Plan bleibt unverändert, bis der Athlet annimmt. Sag ihm, dass es ein Vorschlag ist.']),
            'action'  => [
                'type'              => 'proposal',
                'label'             => "Vorschlag ({$when}): {$after['title']}",
                'recommendation_id' => $rec->id,
                'date'              => $date,
                'when'              => $when,
                'before'            => $before,
                'after'             => $after,
                'reason'            => $reason,
            ],
        ];
    }

    private function setPause(User $user, array $args): array
    {
        $profile = $user->runnerProfile ?? RunnerProfile::firstOrCreate(['user_id' => $user->id]);

        if (empty($args['until'])) {
            if (! $profile->training_paused_until) {
                return ['message' => 'Es läuft keine Pause.', 'action' => null];
            }
            $profile->update(['training_paused_until' => null, 'training_pause_reason' => null]);
            $this->writer->invalidateCoachCaches($user);

            return ['message' => 'Pause beendet.', 'action' => ['type' => 'training_resumed', 'label' => 'Pause beendet', 'reload' => true]];
        }

        try {
            $until = CarbonImmutable::parse($args['until'])->toDateString();
        } catch (\Throwable) {
            return ['message' => 'Ungültiges Datum.', 'action' => null];
        }
        if ($until < today()->toDateString()) {
            return ['message' => 'Das Enddatum liegt in der Vergangenheit.', 'action' => null];
        }

        $reason = mb_substr(trim((string) ($args['reason'] ?? '')), 0, 160) ?: null;
        $profile->update(['training_paused_until' => $until, 'training_pause_reason' => $reason]);
        $count = TrainingSession::where('user_id', $user->id)
            ->whereDate('planned_date', '>=', today()->toDateString())
            ->whereDate('planned_date', '<=', $until)
            ->where('status', 'planned')
            ->update(['status' => 'skipped']);
        $this->writer->invalidateCoachCaches($user);

        return [
            'message' => "Pause bis {$until} gespeichert, {$count} Einheiten übersprungen.",
            'action'  => ['type' => 'training_paused', 'label' => 'Pause bis ' . $this->writer->dayLabel($until) . ($reason ? " ({$reason})" : ''), 'reload' => true],
        ];
    }

    private function updateRaceTarget(User $user, array $args): array
    {
        $event = Event::where('id', (int) ($args['event_id'] ?? 0))->where('user_id', $user->id)->first();
        if (! $event) {
            return ['message' => 'Rennen nicht gefunden — mit get_races nachsehen.', 'action' => null];
        }
        $event->target_time_hours   = max(0, (int) ($args['hours'] ?? 0));
        $event->target_time_minutes = max(0, min(59, (int) ($args['minutes'] ?? 0)));
        $event->save();
        $this->writer->invalidateCoachCaches($user);
        $formatted = $event->target_time_formatted ?? 'aktualisiert';

        return ['message' => "Zielzeit gespeichert: {$formatted}.", 'action' => ['type' => 'event_updated', 'label' => "Zielzeit {$event->name}: {$formatted}", 'reload' => true]];
    }

    private function remember(User $user, string $fact): array
    {
        $fact = trim($fact);
        if ($fact === '') {
            return ['message' => 'Keine Angabe.', 'action' => null];
        }
        $profile = $user->runnerProfile ?? RunnerProfile::firstOrCreate(['user_id' => $user->id]);
        $profile->rememberNote($fact);

        return ['message' => 'Gemerkt.', 'action' => ['type' => 'memory', 'label' => 'Gemerkt: ' . mb_substr($fact, 0, 80)]];
    }

    private function undo(User $user, array $args): array
    {
        $change = CoachChange::where('user_id', $user->id)
            ->when(! empty($args['change_id']), fn ($q) => $q->whereKey((int) $args['change_id']))
            ->whereNull('undone_at')
            ->latest('id')
            ->first();

        if (! $change) {
            return ['message' => 'Keine offene Änderung zum Zurücknehmen gefunden.', 'action' => null];
        }
        if ($blocker = $change->undoBlocker()) {
            return ['message' => $blocker, 'action' => null];
        }
        $change->undo();

        return ['message' => 'Zurückgenommen: ' . implode('; ', $change->lines ?? []), 'action' => ['type' => 'plan_undone', 'label' => 'Rückgängig: ' . implode('; ', $change->lines ?? []), 'reload' => true]];
    }

    // ── Lesen ───────────────────────────────────────────────────────────

    private function range(array $args, int $maxDays): array
    {
        try {
            $from = CarbonImmutable::parse($args['from'] ?? today())->startOfDay();
            $to   = CarbonImmutable::parse($args['to'] ?? $from)->startOfDay();
        } catch (\Throwable) {
            $from = $to = CarbonImmutable::today();
        }
        if ($to < $from) [$from, $to] = [$to, $from];
        if ($from->diffInDays($to) > $maxDays) $to = $from->addDays($maxDays);

        return [$from, $to];
    }

    private function getPlan(User $user, array $args): array
    {
        [$from, $to] = $this->range($args, 28);

        $sessions = TrainingSession::with('activity')
            ->where('user_id', $user->id)
            ->whereDate('planned_date', '>=', $from->toDateString())
            ->whereDate('planned_date', '<=', $to->toDateString())
            ->where('status', '!=', 'skipped')
            ->orderBy('planned_date')->orderBy('sort_order')->orderBy('id')
            ->get();

        $pending = SessionRecommendation::where('user_id', $user->id)->where('status', SessionRecommendation::STATUS_PENDING)->pluck('training_session_id')->all();

        // Feste Termine, die noch kein Plan trägt (vor dem Planstart oder
        // ohne Plan) — sonst fand der Coach nur den nächsten gespeicherten
        // Laufclub (24.11.2026).
        $extra = collect(\App\Services\Plan\FixedAppointment::unplanned($user, $from, $to))->map(fn ($f) => [
            'kind'    => 'appointment',
            'date'    => $f['planned_date'],
            'weekday' => $this->writer->dayLabel($f['planned_date']),
            'type'    => $f['type'],
            'label'   => $f['title'],
            'note'    => 'Fester Termin aus dem Wochenraster, keine ID. Absagen: plan_change op cancel mit date.',
        ]);

        // Der Tagesvorschlag, den „Heute“ zeigt, wenn heute nichts steht.
        $today = today()->toDateString();
        $profile = $user->runnerProfile;
        if ($from->toDateString() <= $today && $today <= $to->toDateString()
            && ! $sessions->contains(fn ($s) => $s->planned_date->toDateString() === $today)
            && ! $extra->contains('date', $today)
            && $profile && (string) $profile->recommendation_date === $today && $profile->today_recommendation) {
            $rec = json_decode($profile->today_recommendation, true);
            if (is_array($rec) && ($rec['type'] ?? 'rest') !== 'rest') {
                $extra->push([
                    'kind'    => 'suggestion',
                    'date'    => $today,
                    'weekday' => $this->writer->dayLabel($today),
                    'type'    => $rec['type'],
                    'label'   => trim(($rec['title'] ?? '') . (! empty($rec['duration_min']) ? " {$rec['duration_min']} min" : '')),
                    'note'    => 'Tagesvorschlag auf „Heute“, keine ID. Ersetzen: set/add mit date heute; Ruhe: set sport rest.',
                ]);
            }
        }

        return [
            'from'     => $from->toDateString(),
            'to'       => $to->toDateString(),
            'without_id' => $extra->values()->all() ?: null,
            'sessions' => $sessions->map(fn ($s) => array_filter([
                'id'       => $s->id,
                'date'     => $s->planned_date->toDateString(),
                'weekday'  => $this->writer->dayLabel($s->planned_date->toDateString()),
                'status'   => $s->status,
                'sport'    => $this->planChange->sportOf($s),
                'type'     => $s->type,
                'label'    => $this->planChange->label($s),
                'pinned'   => $s->pinned_at ? true : null,
                'proposal_open' => in_array($s->id, $pending, true) ? true : null,
                'steps'    => trim(preg_replace('/\s*Bestätige dem Athleten.*$/u', '', $this->writer->stepsLine($s))) ?: null,
            ]))->values()->all(),
        ];
    }

    private function getSession(User $user, int $id): array
    {
        $s = TrainingSession::with('activity')->where('user_id', $user->id)->find($id);
        if (! $s) {
            return ['ok' => false, 'error' => "Einheit {$id} gibt es nicht."];
        }

        $out = [
            'id'          => $s->id,
            'date'        => $s->planned_date->toDateString(),
            'status'      => $s->status,
            'sport'       => $this->planChange->sportOf($s),
            'type'        => $s->type,
            'title'       => $s->title,
            'description' => $s->description,
            'duration_min'=> $s->duration_min,
            'distance_km' => $this->planChange->sportOf($s) === 'run' ? $s->distance_km : null,
            'pace_target' => $s->pace_target,
            'steps'       => $s->steps,
            'exercises'   => collect($s->exercises ?? [])->map(fn ($e) => trim(($e['name'] ?? $e['key'] ?? '') . ' ' . ($e['sets'] ?? '') . '×' . ($e['reps'] ?? '')))->all() ?: null,
            'review'      => $s->coach_review ? mb_substr($s->coach_review, 0, 400) : null,
            'rating'      => $s->rating ?? null,
            'notes'       => $s->notes ?? null,
        ];
        if ($s->activity && ! $s->activity->isStravaOnly()) {
            $out['activity'] = $this->activityRow($s->activity);
        } elseif ($s->activity) {
            $out['activity'] = 'aufgezeichnet (Zahlen nicht verfügbar)';
        }

        return array_filter($out, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    private function getActivities(User $user, array $args): array
    {
        [$from, $to] = $this->range($args, 90);
        $types = match ($args['sport'] ?? 'all') {
            'run'      => ['Run', 'TrailRun', 'VirtualRun'],
            'bike'     => ['Ride', 'VirtualRide', 'EBikeRide', 'MountainBikeRide', 'GravelRide'],
            'swim'     => ['Swim'],
            'strength' => ['WeightTraining', 'Workout', 'Crossfit'],
            default    => null,
        };

        $rows = $user->activities()->withoutStravaOnly()
            ->whereBetween('start_date', [$from->startOfDay(), $to->endOfDay()])
            ->when($types, fn ($q) => $q->whereIn('type', $types))
            ->orderByDesc('start_date')
            ->limit(30)
            ->get(Activity::SUMMARY_COLUMNS);

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'activities' => $rows->map(fn ($a) => $this->activityRow($a))->all()];
    }

    private function activityRow(Activity $a): array
    {
        return array_filter([
            'date'  => $a->start_date?->format('Y-m-d H:i'),
            'name'  => $a->name,
            'type'  => $a->type,
            'km'    => $a->distance ? round($a->distance / 1000, 2) : null,
            'min'   => $a->moving_time ? (int) round($a->moving_time / 60) : null,
            'pace'  => $a->average_speed > 0 && in_array($a->type, ['Run', 'TrailRun', 'VirtualRun'], true) ? PaceFormat::fromSpeed((float) $a->average_speed) . ' min/km' : null,
            'hr'    => $a->average_heartrate ? (int) $a->average_heartrate : null,
            'watts' => $a->weighted_average_watts ?: ($a->average_watts ?: null),
            'elev'  => $a->total_elevation_gain ? (int) $a->total_elevation_gain : null,
        ], fn ($v) => $v !== null);
    }

    private function getHealth(User $user, int $days): array
    {
        $days = max(1, min(14, $days));
        $out = ['days' => []];
        $summary = app(\App\Services\GarminHealthSummary::class);

        for ($i = 0; $i < $days; $i++) {
            $day = CarbonImmutable::today()->subDays($i);
            $row = ['date' => $day->toDateString()];
            if ($user->receivesHealthMetrics()) {
                $h = $summary->forDay($user->id, $day);
                if ($h['has_data']) {
                    $row['watch'] = $h['lines'];
                    if ($h['flags']) $row['flags'] = $h['flags'];
                }
            }
            if ($w = $user->wellbeingEntries()->whereDate('date', $day->toDateString())->first()) {
                $row['tagesform'] = "Energie {$w->energy_level}/10, Schlaf {$w->sleep_quality}/10, Stimmung {$w->mood}/10" . ($w->is_sick ? ', krank' : '') . ($w->notes ? ", Notiz: {$w->notes}" : '');
            }
            $out['days'][] = $row;
        }

        if ($user->receivesHealthMetrics() && ($r = app(\App\Services\Readiness\ReadinessService::class)->forDay($user, CarbonImmutable::today()))) {
            $out['readiness_today'] = \App\Services\Readiness\ReadinessService::promptLine($r);
        }

        return $out;
    }

    private function getRaces(User $user): array
    {
        $events = $user->events()->where('event_date', '>=', today()->subDays(14))->orderBy('event_date')->limit(8)->get();

        return ['races' => $events->map(fn ($e) => array_filter([
            'id'       => $e->id,
            'name'     => $e->name,
            'date'     => $e->event_date->toDateString(),
            'distance' => $e->distance_label,
            'target'   => $e->target_time_formatted,
            'priority' => $e->priority,
            'open_goal'=> $e->goal_type === 'open' ? ($e->open_focus ?? true) : null,
        ]))->values()->all()];
    }
}
