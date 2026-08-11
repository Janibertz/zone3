<?php

namespace App\Services\AI;

/**
 * Der Chat mit dem Coach — inklusive der Werkzeuge, mit denen das Modell
 * Trainingsdaten lesen und Einheiten aendern darf.
 */
class CoachChatService
{
    public function __construct(private readonly OpenAIClient $ai) {}

    /** Coach-Persoenlichkeit und Nutzer an den Transport durchreichen. */
    public function withCoach(?string $personalityPrompt): static
    {
        $this->ai->withCoach($personalityPrompt);

        return $this;
    }

    public function forUser(?int $userId): static
    {
        $this->ai->forUser($userId);

        return $this;
    }

    /**
     * Conversational chat with the user's coach.
     * $history = [{role, content}, ...] (last N messages before the new one)
     * $newMessage = the user's latest message
     */
    public function chatWithCoach(\App\Models\User $user, array $history, string $newMessage): ?string
    {
        $today = now()->toDateString();
        $profile = $user->runnerProfile;

        // Helper: Strava m/s → "M:SS min/km"
        $mpsToMSS = function (float $mps): string {
            if ($mps <= 0) return '—';
            $secPerKm = 1000 / $mps;
            return sprintf('%d:%02d', (int)($secPerKm / 60), (int)$secPerKm % 60);
        };

        // Helper: threshold_speed float (e.g. 5.5) → "5:30"
        $floatMinToMSS = function (?float $min): string {
            if (!$min) return '—';
            $m = (int)$min;
            $s = (int)round(($min - $m) * 60);
            return sprintf('%d:%02d', $m, $s);
        };

        // ── Runner profile ────────────────────────────────────────────────
        $profileLines = [];
        if ($profile) {
            if ($profile->threshold_speed) {
                $profileLines[] = 'Schwellenpace: ' . $floatMinToMSS($profile->threshold_speed) . ' min/km';
            }
            if ($profile->threshold_heart_rate) $profileLines[] = 'LTHR: ' . $profile->threshold_heart_rate . ' bpm';
            if ($profile->max_heart_rate)       $profileLines[] = 'Max HR: ' . $profile->max_heart_rate . ' bpm';

            if (!empty($profile->pace_zones)) {
                $zoneStr = collect($profile->pace_zones)->map(
                    fn ($r, $z) => "Z{$z}: " . $floatMinToMSS($r['min'] ?? null) . '–' . $floatMinToMSS($r['max'] ?? null)
                )->implode(' | ');
                if ($zoneStr) $profileLines[] = 'Pace-Zonen: ' . $zoneStr;
            }
        }

        // Running experience from first Strava run
        $firstRun = $user->activities()->where('type', 'Run')->oldest('start_date')->first();
        if ($firstRun) {
            $months = (int)$firstRun->start_date->diffInMonths(now());
            $since  = $months < 24 ? "{$months} Monate" : round($months / 12, 1) . ' Jahre';
            $profileLines[] = "Läuft seit: ca. {$since} (erste Aktivität: {$firstRun->start_date->format('M Y')})";
        }

        // ── Weekly km (last 4 calendar weeks) ────────────────────────────
        $weeklyLines = [];
        for ($w = 0; $w < 4; $w++) {
            $wStart = now()->startOfWeek()->subWeeks($w);
            $wEnd   = (clone $wStart)->addWeek();
            $km     = round($user->activities()
                ->where('type', 'Run')
                ->whereBetween('start_date', [$wStart, $wEnd])
                ->sum('distance') / 1000, 1);
            $label = match ($w) { 0 => 'Aktuelle Woche', 1 => 'Letzte Woche', default => "Vor {$w} Wochen" };
            $weeklyLines[] = "{$label}: {$km} km";
        }

        // ── Training distribution last 30 days (completed) ───────────────
        $typeMap = [
            'easy_run' => 'Lockere Läufe', 'tempo_run' => 'Tempoläufe',
            'interval' => 'Intervalle', 'long_run' => 'Lange Läufe',
            'progressive_run' => 'Progressive Läufe', 'test_run' => 'Testläufe',
            'race_prep' => 'Rennvorbereitung',
        ];
        $completedByType = \App\Models\TrainingSession::where('user_id', $user->id)
            ->where('status', 'completed')
            ->where('planned_date', '>=', now()->subDays(30)->toDateString())
            ->selectRaw('type, count(*) as cnt')
            ->groupBy('type')
            ->pluck('cnt', 'type')
            ->toArray();
        $distLines = array_map(
            fn ($type, $cnt) => ($typeMap[$type] ?? $type) . ': ' . $cnt . '×',
            array_keys($completedByType), $completedByType
        );

        // ── Recent runs (last 10) with full metrics ───────────────────────
        $recentRuns = $user->activities()
            ->where('type', 'Run')
            ->orderByDesc('start_date')
            ->limit(10)
            ->get()
            ->map(function ($a) use ($mpsToMSS) {
                $km    = number_format(($a->distance ?? 0) / 1000, 1);
                $pace  = $a->average_speed > 0 ? $mpsToMSS((float)$a->average_speed) . ' min/km' : '—';
                $dur   = $a->moving_time ? (int)round($a->moving_time / 60) . ' min' : '';
                $hr    = $a->average_heartrate ? (int)$a->average_heartrate . ' bpm' : '';
                $hrMax = $a->max_heartrate    ? '/ ' . (int)$a->max_heartrate . ' max' : '';
                $parts = array_filter([$km . ' km', $pace, $dur, trim($hr . ' ' . $hrMax)]);
                return '- ' . $a->start_date->format('d.m.') . ' "' . $a->name . '": ' . implode(' | ', $parts);
            })
            ->implode("\n");

        // ── Today's planned session ───────────────────────────────────────
        $todaySession = \App\Models\TrainingSession::where('user_id', $user->id)
            ->whereDate('planned_date', $today)
            ->where('status', '!=', 'skipped')
            ->orderBy('sort_order')
            ->first();

        // ── Upcoming sessions (next 7 days) ───────────────────────────────
        $upcomingSessions = \App\Models\TrainingSession::where('user_id', $user->id)
            ->whereDate('planned_date', '>', $today)
            ->whereDate('planned_date', '<=', now()->addDays(7)->toDateString())
            ->where('type', '!=', 'rest')
            ->orderBy('planned_date')
            ->limit(5)
            ->get();

        // ── Upcoming events (all) ─────────────────────────────────────────
        $events = $user->events()
            ->where('event_date', '>=', $today)
            ->orderBy('event_date')
            ->limit(4)
            ->get();

        // ── Today's wellbeing ─────────────────────────────────────────────
        $todayWellbeing = $user->wellbeingEntries()->whereDate('date', $today)->first();

        // ── Assemble context sections ─────────────────────────────────────
        $ctx = [];

        if ($profileLines) {
            $ctx[] = "ATHLETENPROFIL:\n" . implode("\n", $profileLines);
        }

        if ($weeklyLines) {
            $ctx[] = "WOCHENKILOMETER:\n" . implode("\n", $weeklyLines);
        }

        if ($distLines) {
            $ctx[] = "TRAININGSVERTEILUNG (letzte 30 Tage, abgeschlossen):\n" . implode(', ', $distLines);
        }

        if ($recentRuns) {
            $ctx[] = "LETZTE LÄUFE (inkl. Pace & HR):\n{$recentRuns}";
        }

        if ($todaySession) {
            if ($todaySession->type === 'rest') {
                $s = $todaySession->status === 'completed' ? ' (bereits erledigt)' : '';
                $ctx[] = "HEUTIGES TRAINING{$s}: Ruhetag";
            } else {
                $d = "Typ: {$todaySession->type}, Titel: \"{$todaySession->title}\"";
                if ($todaySession->distance_km) $d .= ", {$todaySession->distance_km} km";
                if ($todaySession->duration_min) $d .= ", {$todaySession->duration_min} min";
                if ($todaySession->pace_target && $todaySession->pace_target !== 'null') $d .= ", Pace-Ziel: {$todaySession->pace_target} min/km";
                if ($todaySession->zone)         $d .= ", Zone {$todaySession->zone}";
                $s    = $todaySession->status === 'completed' ? ' (bereits absolviert)' : '';
                $desc = $todaySession->description ? "\n  Details: {$todaySession->description}" : '';
                $ctx[] = "HEUTIGES TRAINING{$s}:\n  {$d}{$desc}";
            }
        } else {
            $ctx[] = "HEUTIGES TRAINING: Kein Training geplant.";
        }

        if ($upcomingSessions->isNotEmpty()) {
            $lines = $upcomingSessions->map(fn ($s) => sprintf(
                '- %s: %s (%s%s)',
                $s->planned_date->format('d.m.'), $s->title, $s->type,
                $s->distance_km ? ", {$s->distance_km} km" : ''
            ))->implode("\n");
            $ctx[] = "NÄCHSTE EINHEITEN (7 Tage):\n{$lines}";
        }

        if ($events->isNotEmpty()) {
            $lines = $events->map(function ($e) {
                $days   = (int)now()->startOfDay()->diffInDays($e->event_date->copy()->startOfDay(), false);
                $priStr = match ($e->priority) { 'A' => '★ A-Event', 'B' => 'B-Event', default => 'C-Event' };
                $target = $e->target_time_formatted ? ", Ziel: {$e->target_time_formatted}" : '';
                return "- {$e->name} ({$e->distance_label}) – {$e->event_date->format('d.m.Y')} (in {$days} Tagen) [{$priStr}{$target}]";
            })->implode("\n");
            $ctx[] = "KOMMENDE EVENTS:\n{$lines}";
        }

        if ($todayWellbeing) {
            $ctx[] = "WELLBEING HEUTE: Energie {$todayWellbeing->energy_level}/10, Schlaf {$todayWellbeing->sleep_quality}/10, Stimmung {$todayWellbeing->mood}/10";
        }

        $contextBlock = "\n\n=== ATHLETEN-DATEN (Stand: {$today}) ===\n" . implode("\n\n", $ctx) . "\n=== ENDE ===";

        $coachName = $user->coach?->name ?? 'Coach';

        $systemPrompt = $this->ai->systemPrompt(
            "Du bist {$coachName}, der persönliche Lauf-Coach von {$user->name}. " .
            "Du kennst alle Trainingsdaten deines Athleten — Paces, Herzfrequenzen, Schwellenpace, Wochenkilometer, Events — und nutzt sie für präzise, datenbasierte Antworten wie ein echter Trainer, der seinen Athleten wirklich kennt. " .
            "Antworte immer auf Deutsch. Sprich den Athleten direkt mit 'du' an. " .
            "Passe die Antwortlänge der Frage an: Kurze Fragen → 1–3 Sätze. Analysefragen, Trainingsempfehlungen oder 'Was soll ich trainieren?' → ausführlich und strukturiert mit konkreten Zahlen aus den Daten (Paces, HR, km). " .
            "Nutze Markdown (Listen, Fettschrift, Tabellen) für strukturierte Antworten. " .
            "Wenn du für eine präzisere Antwort mehr Daten brauchst (z.B. Km-Splits, genaue Streckenbeschaffenheit), frag gezielt danach. " .
            "Stütze dich IMMER auf die echten Zahlen aus den Athleten-Daten — niemals auf generische Empfehlungen. " .
            "Wenn heute eine Trainingseinheit geplant ist, empfehle niemals etwas Gegensätzliches." .
            $contextBlock
        );

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($history as $msg) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $newMessage];

        return $this->ai->chat('coach_chat', $messages, 0.8, 2500, 60);
    }

    /** Tool definitions for coach function calling. */

    /**
     * Coach chat with tool use: memory, session modification, skip sessions, event target.
     * Returns ['reply' => string|null, 'actions' => array].
     */
    public function chatWithCoachTools(\App\Models\User $user, array $history, string $newMessage): array
    {
        $today   = now()->toDateString();
        $profile = $user->runnerProfile;

        $mpsToMSS = function (float $mps): string {
            if ($mps <= 0) return '—';
            $s = 1000 / $mps;
            return sprintf('%d:%02d', (int)($s / 60), (int)$s % 60);
        };
        $floatMinToMSS = function (?float $min): string {
            if (!$min) return '—';
            $m = (int)$min;
            return sprintf('%d:%02d', $m, (int)round(($min - $m) * 60));
        };

        // Profile
        $profileLines = [];
        if ($profile) {
            if ($profile->threshold_speed)      $profileLines[] = 'Schwellenpace: ' . $floatMinToMSS($profile->threshold_speed) . ' min/km';
            if ($profile->threshold_heart_rate) $profileLines[] = 'LTHR: ' . $profile->threshold_heart_rate . ' bpm';
            if ($profile->max_heart_rate)       $profileLines[] = 'Max HR: ' . $profile->max_heart_rate . ' bpm';
            if (!empty($profile->pace_zones)) {
                $zStr = collect($profile->pace_zones)->map(fn ($r, $z) => "Z{$z}: " . $floatMinToMSS($r['min'] ?? null) . '–' . $floatMinToMSS($r['max'] ?? null))->implode(' | ');
                if ($zStr) $profileLines[] = 'Pace-Zonen: ' . $zStr;
            }
        }
        $firstRun = $user->activities()->where('type', 'Run')->oldest('start_date')->first();
        if ($firstRun) {
            $months = (int)$firstRun->start_date->diffInMonths(now());
            $profileLines[] = 'Läuft seit: ca. ' . ($months < 24 ? "{$months} Monate" : round($months / 12, 1) . ' Jahre');
        }

        // Weekly km
        $weeklyLines = [];
        for ($w = 0; $w < 4; $w++) {
            $wStart = now()->startOfWeek()->subWeeks($w);
            $km     = round($user->activities()->where('type','Run')->whereBetween('start_date', [$wStart, (clone $wStart)->addWeek()])->sum('distance') / 1000, 1);
            $weeklyLines[] = match ($w) { 0 => 'Aktuelle Woche', 1 => 'Letzte Woche', default => "Vor {$w} Wochen" } . ": {$km} km";
        }

        // Training distribution
        $typeMap = ['easy_run'=>'Lockere Läufe','tempo_run'=>'Tempoläufe','interval'=>'Intervalle','long_run'=>'Lange Läufe','progressive_run'=>'Progressive Läufe','test_run'=>'Testläufe','race_prep'=>'Rennvorbereitung'];
        $byType  = \App\Models\TrainingSession::where('user_id',$user->id)->where('status','completed')->where('planned_date','>=',now()->subDays(30)->toDateString())->selectRaw('type, count(*) as cnt')->groupBy('type')->pluck('cnt','type')->toArray();
        $distLines = array_map(fn($t,$c) => ($typeMap[$t]??$t).': '.$c.'×', array_keys($byType), $byType);

        // Recent runs
        $recentRuns = $user->activities()->where('type','Run')->orderByDesc('start_date')->limit(10)->get()->map(function($a) use ($mpsToMSS) {
            $km    = number_format(($a->distance??0)/1000,1);
            $pace  = $a->average_speed>0 ? $mpsToMSS((float)$a->average_speed).' min/km' : '—';
            $dur   = $a->moving_time ? (int)round($a->moving_time/60).' min' : '';
            $hr    = $a->average_heartrate ? (int)$a->average_heartrate.' bpm' : '';
            $hrMax = $a->max_heartrate    ? '/ '.(int)$a->max_heartrate.' max' : '';
            return '- '.$a->start_date->format('d.m.').' "'.$a->name.'": '.implode(' | ', array_filter([$km.' km',$pace,$dur,trim($hr.' '.$hrMax)]));
        })->implode("\n");

        // Today's session
        $todaySession = \App\Models\TrainingSession::where('user_id',$user->id)->whereDate('planned_date',$today)->where('status','!=','skipped')->orderBy('sort_order')->first();

        // Upcoming
        $upcoming = \App\Models\TrainingSession::where('user_id',$user->id)->whereDate('planned_date','>',$today)->whereDate('planned_date','<=',now()->addDays(7)->toDateString())->where('type','!=','rest')->orderBy('planned_date')->limit(5)->get();

        // Events (include ID for tool use)
        $events = $user->events()->where('event_date','>=',$today)->orderBy('event_date')->limit(4)->get();

        // Wellbeing
        $wellbeing = $user->wellbeingEntries()->whereDate('date',$today)->first();

        // Build context
        $ctx = [];
        $coachNotes = $profile?->coach_notes ? trim($profile->coach_notes) : null;
        if ($coachNotes) $ctx[] = "WAS ICH ÜBER DICH WEISS:\n{$coachNotes}";
        if ($profileLines) $ctx[] = "ATHLETENPROFIL:\n".implode("\n",$profileLines);
        if ($weeklyLines)  $ctx[] = "WOCHENKILOMETER:\n".implode("\n",$weeklyLines);
        if ($distLines)    $ctx[] = "TRAININGSVERTEILUNG (30 Tage):\n".implode(', ',$distLines);
        if ($recentRuns)   $ctx[] = "LETZTE LÄUFE:\n{$recentRuns}";

        if ($todaySession) {
            if ($todaySession->type === 'rest') {
                $ctx[] = "HEUTIGES TRAINING (ID:{$todaySession->id})".(($todaySession->status==='completed')?' (erledigt)':'').": Ruhetag";
            } else {
                $d = "Typ:{$todaySession->type}, Titel:\"{$todaySession->title}\"";
                if ($todaySession->distance_km) $d .= ", {$todaySession->distance_km}km";
                if ($todaySession->duration_min) $d .= ", {$todaySession->duration_min}min";
                if ($todaySession->pace_target && $todaySession->pace_target!=='null') $d .= ", Pace:{$todaySession->pace_target}";
                if ($todaySession->zone) $d .= ", Zone{$todaySession->zone}";
                $s = $todaySession->status==='completed' ? ' (absolviert)' : '';
                $ctx[] = "HEUTIGES TRAINING (ID:{$todaySession->id}){$s}:\n  {$d}".($todaySession->description ? "\n  Beschreibung: {$todaySession->description}" : '');
            }
        } else {
            $ctx[] = "HEUTIGES TRAINING: Kein Training geplant.";
        }

        if ($upcoming->isNotEmpty()) {
            $ctx[] = "NÄCHSTE 7 TAGE:\n".$upcoming->map(fn($s)=>'- '.$s->planned_date->format('d.m.').' '.$s->title.' ('.$s->type.($s->distance_km?", {$s->distance_km}km":'').')')->implode("\n");
        }
        if ($events->isNotEmpty()) {
            $ctx[] = "EVENTS:\n".$events->map(function($e){
                $days=(int)now()->startOfDay()->diffInDays($e->event_date->copy()->startOfDay(),false);
                $target=$e->target_time_formatted ? ", Ziel:{$e->target_time_formatted}" : '';
                return "- [ID:{$e->id}] {$e->name} ({$e->distance_label}) {$e->event_date->format('d.m.Y')} (in {$days}d) [".match($e->priority){'A'=>'★A','B'=>'B',default=>'C'}."{$target}]";
            })->implode("\n");
        }
        if ($wellbeing) $ctx[] = "WELLBEING: Energie {$wellbeing->energy_level}/10, Schlaf {$wellbeing->sleep_quality}/10, Stimmung {$wellbeing->mood}/10";

        $contextBlock = "\n\n=== ATHLETEN-DATEN ({$today}) ===\n".implode("\n\n",$ctx)."\n=== ENDE ===";
        $coachName = $user->coach?->name ?? 'Coach';

        $systemPrompt = $this->ai->systemPrompt(
            "Du bist {$coachName}, der persönliche Lauf-Coach von {$user->name}. ".
            "Du kennst alle Trainingsdaten und antwortest wie ein echter Coach der seinen Athleten kennt. ".
            "Du hast Werkzeuge um: Infos dauerhaft zu merken (remember_user_fact), die heutige Einheit anzupassen (modify_today_session), Einheiten bei Krankheit/Urlaub zu überspringen (skip_training_sessions), Zielzeiten zu aktualisieren (update_event_target). ".
            "Nutze Tools proaktiv: Athlet sagt er ist krank → überspringe Einheiten. 'Zu leicht' → ändere Einheit. Präferenz geäußert → merke sie dir. ".
            "Antworte auf Deutsch, sprich mit 'du'. Passe Länge der Antwort der Frage an. Nutze Markdown für strukturierte Antworten. ".
            "Stütze dich IMMER auf echte Zahlen aus den Daten.".
            $contextBlock
        );

        $messages = [['role'=>'system','content'=>$systemPrompt]];
        foreach ($history as $msg) $messages[] = ['role'=>$msg['role'],'content'=>$msg['content']];
        $messages[] = ['role'=>'user','content'=>$newMessage];

        $tools        = $this->coachTools();
        $actionsTaken = [];

        for ($i = 0; $i < 3; $i++) {
            $body   = $this->ai->chatWithTools('coach_chat', $messages, $tools);
            $choice = $body['choices'][0] ?? null;

            if (! $choice) break;

            $assistantMsg = $choice['message'];
            $finishReason = $choice['finish_reason'] ?? 'stop';

            if ($finishReason !== 'tool_calls' || empty($assistantMsg['tool_calls'])) {
                return ['reply' => $assistantMsg['content'] ?? '', 'actions' => array_values(array_filter($actionsTaken))];
            }

            $messages[] = $assistantMsg;
            foreach ($assistantMsg['tool_calls'] as $toolCall) {
                $result = $this->executeCoachTool($user, $toolCall['function']['name'], json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? []);
                if ($result['action']) $actionsTaken[] = $result['action'];
                $messages[] = ['role'=>'tool','tool_call_id'=>$toolCall['id'],'content'=>$result['message']];
            }
        }

        return ['reply' => null, 'actions' => array_values(array_filter($actionsTaken))];
    }

    private function coachTools(): array
    {
        return [
            ['type' => 'function', 'function' => [
                'name'        => 'remember_user_fact',
                'description' => 'Speichere eine wichtige Tatsache, Vorliebe oder Eigenschaft des Athleten dauerhaft im Profil – z.B. Vorlieben, Stärken/Schwächen, Verletzungshistorie, Trainingspräferenzen.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'fact' => ['type' => 'string', 'description' => 'Die zu merkende Information, prägnant formuliert'],
                ], 'required' => ['fact']],
            ]],
            ['type' => 'function', 'function' => [
                'name'        => 'modify_today_session',
                'description' => 'Ändere die heutige Trainingseinheit. Nutze dies bei "zu leicht", "mach es schwerer", "ich möchte heute Intervalle für 60 min" etc. Alle Felder sind optional – ändere nur was nötig.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'type'         => ['type' => 'string', 'enum' => ['easy_run','tempo_run','interval','long_run','progressive_run','test_run','race_prep'], 'description' => 'Trainingstyp'],
                    'title'        => ['type' => 'string', 'description' => 'Titel der Einheit'],
                    'description'  => ['type' => 'string', 'description' => 'Detaillierte Beschreibung des Workouts inkl. Intervallstruktur'],
                    'distance_km'  => ['type' => 'number', 'description' => 'Zieldistanz in km'],
                    'duration_min' => ['type' => 'integer', 'description' => 'Zieldauer in Minuten'],
                    'pace_target'  => ['type' => 'string', 'description' => 'Zielpace im Format M:SS'],
                    'zone'         => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'description' => 'Herzfrequenzzone'],
                ]],
            ]],
            ['type' => 'function', 'function' => [
                'name'        => 'skip_training_sessions',
                'description' => 'Markiere Trainingseinheiten als übersprungen – bei Krankheit, Verletzung, Urlaub oder erzwungener Pause.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'date_from' => ['type' => 'string', 'description' => 'Startdatum YYYY-MM-DD'],
                    'date_to'   => ['type' => 'string', 'description' => 'Enddatum YYYY-MM-DD'],
                    'reason'    => ['type' => 'string', 'description' => 'Grund (z.B. "Grippe", "Knieprobleme", "Urlaub")'],
                ], 'required' => ['date_from', 'date_to']],
            ]],
            ['type' => 'function', 'function' => [
                'name'        => 'update_event_target',
                'description' => 'Aktualisiere die Zielzeit für ein Event, wenn der Athlet sie anpassen möchte.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'event_id'       => ['type' => 'integer', 'description' => 'Event-ID aus den Athleten-Daten'],
                    'target_hours'   => ['type' => 'integer', 'description' => 'Stunden der Zielzeit'],
                    'target_minutes' => ['type' => 'integer', 'description' => 'Minuten (0–59)'],
                ], 'required' => ['event_id', 'target_hours', 'target_minutes']],
            ]],
        ];
    }

    /** Execute a single coach tool call. Returns ['message', 'action']. */

    private function executeCoachTool(\App\Models\User $user, string $toolName, array $args): array
    {
        switch ($toolName) {
            case 'remember_user_fact': {
                $fact = trim($args['fact'] ?? '');
                if (!$fact) return ['message' => 'Keine Angabe.', 'action' => null];
                $profile = $user->runnerProfile ?? \App\Models\RunnerProfile::firstOrCreate(['user_id' => $user->id]);
                $existing = $profile->coach_notes ?? '';
                $profile->coach_notes = trim($existing . "\n- " . $fact);
                $profile->save();
                return ['message' => 'Gespeichert.', 'action' => ['type' => 'memory', 'label' => 'Gemerkt: ' . mb_substr($fact, 0, 80)]];
            }

            case 'modify_today_session': {
                $today   = now()->toDateString();
                $session = \App\Models\TrainingSession::where('user_id', $user->id)
                    ->whereDate('planned_date', $today)->where('status', '!=', 'skipped')->orderBy('sort_order')->first();
                if (!$session) {
                    $session = new \App\Models\TrainingSession();
                    $session->user_id = $user->id;
                    $session->planned_date = $today;
                    $session->status = 'planned';
                    $session->sort_order = 1;
                }
                foreach (['type','title','description','distance_km','duration_min','pace_target','zone'] as $f) {
                    if (array_key_exists($f, $args)) $session->{$f} = $args[$f];
                }
                // Clear cached steps and nutrition tips so they get regenerated with the new parameters
                $session->steps         = null;
                $session->nutrition_tips = null;
                $session->save();

                // Regenerate the dashboard recommendation + daily message with the new context
                $this->invalidateCoachCaches($user);
                $label = $session->title ?? ($args['type'] ?? 'Training');
                return ['message' => 'Einheit aktualisiert.', 'action' => ['type' => 'session_modified', 'label' => 'Training angepasst: ' . $label, 'reload' => true]];
            }

            case 'skip_training_sessions': {
                $from   = $args['date_from'] ?? now()->toDateString();
                $to     = $args['date_to']   ?? $from;
                $reason = $args['reason']    ?? '';
                $count  = \App\Models\TrainingSession::where('user_id', $user->id)
                    ->whereBetween('planned_date', [$from, $to])->where('status', 'planned')->update(['status' => 'skipped']);

                // A skipped session changes today's context → refresh recommendation + daily message
                if ($count > 0) {
                    $this->invalidateCoachCaches($user);
                }

                $detail = $reason ? " ($reason)" : '';
                return ['message' => "{$count} Einheiten übersprungen.", 'action' => ['type' => 'sessions_skipped', 'label' => "{$count} " . ($count === 1 ? 'Einheit' : 'Einheiten') . " übersprungen{$detail}", 'reload' => true]];
            }

            case 'update_event_target': {
                $event = \App\Models\Event::where('id', $args['event_id'] ?? 0)->where('user_id', $user->id)->first();
                if (!$event) return ['message' => 'Event nicht gefunden.', 'action' => null];
                $event->target_time_hours   = max(0, (int)($args['target_hours'] ?? 0));
                $event->target_time_minutes = max(0, min(59, (int)($args['target_minutes'] ?? 0)));
                $event->save();
                $this->invalidateCoachCaches($user);
                $formatted = $event->target_time_formatted ?? 'aktualisiert';
                return ['message' => "Zielzeit gespeichert: {$formatted}.", 'action' => ['type' => 'event_updated', 'label' => "Zielzeit {$event->name}: {$formatted}", 'reload' => true]];
            }

            default:
                return ['message' => 'Unbekanntes Tool.', 'action' => null];
        }
    }

    /**
     * Invalidate cached coach outputs (dashboard recommendation + daily message)
     * so they regenerate with fresh context after a plan/session change.
     */
    private function invalidateCoachCaches(\App\Models\User $user): void
    {
        $profile = $user->runnerProfile ?? \App\Models\RunnerProfile::firstOrCreate(['user_id' => $user->id]);
        $profile->update([
            'today_recommendation' => null,
            'recommendation_date'  => null,
            'daily_message'        => null,
            'daily_message_date'   => null,
        ]);
    }

}
