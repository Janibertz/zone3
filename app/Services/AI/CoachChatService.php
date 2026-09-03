<?php

namespace App\Services\AI;

use App\Services\PaceFormat;

/**
 * Der Chat mit dem Coach — inklusive der Werkzeuge, mit denen das Modell
 * Trainingsdaten lesen und Einheiten aendern darf.
 */
class CoachChatService
{
    use TalksToOpenAI;

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
            return PaceFormat::fromSeconds($secPerKm);
        };

        // Helper: threshold_speed float (e.g. 5.5) → "5:30"
        $floatMinToMSS = fn (?float $min): string => PaceFormat::fromMinutes($min);

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
            return PaceFormat::fromSeconds($s);
        };
        $floatMinToMSS = function (?float $min): string {
            if (!$min) return '—';
            $m = (int)$min;
            return PaceFormat::fromMinutes($min);
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
            "Du kannst den Trainingsplan wirklich verändern. Deine Werkzeuge: Einheiten anlegen (create_training_session), ändern (modify_training_session, an JEDEM Tag – nicht nur heute), verschieben (move_training_session), löschen (delete_training_session), bei Krankheit/Urlaub überspringen (skip_training_sessions), Zielzeiten anpassen (update_event_target) und Infos dauerhaft merken (remember_user_fact). ".
            "Nutze Tools proaktiv: Athlet sagt er ist krank → überspringe Einheiten. 'Zu leicht' → ändere Einheit. Präferenz geäußert → merke sie dir. ".
            // Der Coach hatte lange nur ein Werkzeug fuer HEUTE. Bat der
            // Athlet um eine Aenderung am Sonntag, passte kein Werkzeug — und
            // das Modell beschrieb die Aenderung einfach, statt sie
            // vorzunehmen. Der Athlet las eine perfekte Zusage und fand
            // danach den alten Plan vor.
            "WICHTIG: Behaupte NIE, du hättest etwas am Plan geändert, ohne das passende Werkzeug aufgerufen zu haben. Formulierungen wie „den ersetzen wir“ oder „ich habe eingeplant“ sind nur erlaubt, nachdem das Werkzeug gelaufen ist und Erfolg gemeldet hat. ".
            "Will der Athlet eine Einheit an einem bestimmten Tag, rufe das Werkzeug SOFORT auf und beschreibe die Einheit erst danach. Kannst du etwas nicht ändern, sage das klar, statt es zu beschreiben. ".
            "Pro Tag gibt es höchstens EIN Lauftraining. Soll an einem Tag etwas anderes laufen, ändere die bestehende Einheit — lege keine zweite daneben. ".
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
                'name'        => 'modify_training_session',
                'description' => 'Ändere eine geplante Trainingseinheit — an einem beliebigen Tag, nicht nur heute. Nutze dies bei "zu leicht", "mach es schwerer", "am Sonntag lieber 25 km". Ohne "date" gilt heute. Steht an dem Tag noch nichts, wird die Einheit angelegt. WICHTIG: Änderst du Typ oder Umfang eines Laufs, gib IMMER distance_km UND duration_min mit — sonst bleiben die Zahlen der alten Einheit stehen und widersprechen dem Titel.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'date'         => ['type' => 'string', 'description' => 'Tag der Einheit als YYYY-MM-DD. Weglassen für heute.'],
                    'type'         => ['type' => 'string', 'enum' => ['easy_run','tempo_run','interval','long_run','progressive_run','test_run','race_prep','strength','core','mobility','rest'], 'description' => 'Trainingstyp'],
                    'title'        => ['type' => 'string', 'description' => 'Titel der Einheit'],
                    'description'  => ['type' => 'string', 'description' => 'Detaillierte Beschreibung des Workouts inkl. Intervallstruktur'],
                    'distance_km'  => ['type' => 'number', 'description' => 'Zieldistanz in km'],
                    'duration_min' => ['type' => 'integer', 'description' => 'Zieldauer in Minuten'],
                    'pace_target'  => ['type' => 'string', 'description' => 'Zielpace im Format M:SS'],
                    'zone'         => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'description' => 'Herzfrequenzzone'],
                ]],
            ]],
            ['type' => 'function', 'function' => [
                'name'        => 'create_training_session',
                'description' => 'Lege eine zusätzliche Trainingseinheit im Plan an — z.B. eine Krafteinheit neben einem Lauf. Für einen Lauf an einem Tag, an dem schon ein Lauf steht, nimm modify_training_session: pro Tag gibt es nur EIN Lauftraining.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'date'         => ['type' => 'string', 'description' => 'Tag der Einheit als YYYY-MM-DD'],
                    'type'         => ['type' => 'string', 'enum' => ['easy_run','tempo_run','interval','long_run','progressive_run','test_run','race_prep','strength','core','mobility'], 'description' => 'Trainingstyp'],
                    'title'        => ['type' => 'string', 'description' => 'Titel der Einheit'],
                    'description'  => ['type' => 'string', 'description' => 'Detaillierte Beschreibung des Workouts'],
                    'distance_km'  => ['type' => 'number', 'description' => 'Zieldistanz in km'],
                    'duration_min' => ['type' => 'integer', 'description' => 'Zieldauer in Minuten'],
                    'pace_target'  => ['type' => 'string', 'description' => 'Zielpace im Format M:SS'],
                    'zone'         => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'description' => 'Herzfrequenzzone'],
                ], 'required' => ['date', 'type']],
            ]],
            ['type' => 'function', 'function' => [
                'name'        => 'move_training_session',
                'description' => 'Verschiebe eine geplante Einheit auf einen anderen Tag — "der lange Lauf passt mir Samstag besser". Steht am Zieltag schon ein Lauf, wird er ersetzt.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'from_date' => ['type' => 'string', 'description' => 'Bisheriger Tag YYYY-MM-DD'],
                    'to_date'   => ['type' => 'string', 'description' => 'Neuer Tag YYYY-MM-DD'],
                    'type'      => ['type' => 'string', 'description' => 'Nur nötig, wenn am Ausgangstag mehrere Einheiten stehen'],
                ], 'required' => ['from_date', 'to_date']],
            ]],
            ['type' => 'function', 'function' => [
                'name'        => 'delete_training_session',
                'description' => 'Lösche eine geplante Einheit ganz aus dem Plan. Für "war krank" oder "hab ich ausgelassen" nimm stattdessen skip_training_sessions — das bleibt in der Historie sichtbar. Löschen ist für Einheiten, die es gar nicht erst geben sollte.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'date' => ['type' => 'string', 'description' => 'Tag der Einheit YYYY-MM-DD'],
                    'type' => ['type' => 'string', 'description' => 'Nur nötig, wenn an dem Tag mehrere Einheiten stehen'],
                ], 'required' => ['date']],
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

    /** Ersatztitel, wenn das Modell keinen mitgibt. */
    private function sessionTitleFor(string $type): string
    {
        return [
            'easy_run'        => 'Lockerer Lauf',
            'tempo_run'       => 'Tempolauf',
            'interval'        => 'Intervalltraining',
            'long_run'        => 'Langer Lauf',
            'progressive_run' => 'Progressiver Lauf',
            'test_run'        => 'Testlauf',
            'race_prep'       => 'Rennvorbereitung',
            'rest'            => 'Ruhetag',
        ][$type] ?? 'Training';
    }

    /** Passende Intensitaet zum Typ, falls keine gesetzt ist. */
    private function intensityFor(string $type): string
    {
        return match ($type) {
            'rest'                            => 'rest',
            'easy_run'                        => 'low',
            'interval', 'test_run', 'race_prep' => 'high',
            default                           => 'medium',
        };
    }

    /**
     * Datum eines Werkzeugaufrufs prüfen. Vergangene Tage sind für Änderungen
     * gesperrt — was gelaufen ist, ist gelaufen, und ein Plan, der rückwirkend
     * umgeschrieben wird, macht jede Auswertung wertlos.
     */
    private function toolDate(?string $date, bool $allowPast = false): ?string
    {
        if (! $date) {
            return now()->toDateString();
        }

        try {
            $parsed = \Carbon\CarbonImmutable::parse($date)->toDateString();
        } catch (\Throwable) {
            return null;
        }

        if (! $allowPast && $parsed < now()->toDateString()) {
            return null;
        }

        return $parsed;
    }

    /** Eine geplante Einheit an einem Tag, optional nach Typ. */
    private function plannedSessionOn(\App\Models\User $user, string $date, ?string $type = null): ?\App\Models\TrainingSession
    {
        return \App\Models\TrainingSession::where('user_id', $user->id)
            ->whereDate('planned_date', $date)
            ->where('status', '!=', 'skipped')
            ->when($type, fn ($q) => $q->where('type', $type))
            ->orderBy('sort_order')
            ->first();
    }

    /** Das Lauftraining eines Tages — davon gibt es höchstens eines. */
    private function plannedRunOn(\App\Models\User $user, string $date): ?\App\Models\TrainingSession
    {
        return \App\Models\TrainingSession::where('user_id', $user->id)
            ->whereDate('planned_date', $date)
            ->where('status', 'planned')
            ->whereIn('type', \App\Services\TrainingPlanValidator::RUN_TYPES)
            ->orderBy('sort_order')
            ->first();
    }

    private function isRun(string $type): bool
    {
        return in_array($type, \App\Services\TrainingPlanValidator::RUN_TYPES, true);
    }

    /**
     * Eine neue Einheit, die im aktiven Plan landet.
     *
     * Ohne training_plan_id taucht sie auf der Planseite nicht auf — die lädt
     * ausschließlich Einheiten des Plans. Der Athlet bekäme dann eine Zusage
     * im Chat und einen unveränderten Plan.
     */
    /**
     * Ein Tag, dem der Coach die letzte Einheit genommen hat, bekommt einen
     * Ruhetag.
     *
     * Genau hier entstand das Loch im Plan, und der Hergang ist banal: Jan
     * hatte das Mittwochstraining aus Zeitgruenden abgesagt, die Neuberechnung
     * legte es auf Donnerstag, und dann sagte er dem Coach, er schaffe es
     * doch noch am Mittwoch. Der Coach schob die Einheit zurueck — und
     * `move_training_session` setzt nur `planned_date` um. Der Donnerstag
     * blieb leer zurueck, ohne dass irgendjemand nachsah.
     *
     * Die Kontrolle in `RegeneratePlanJob::sealGaps()` faengt das nicht: sie
     * laeuft in der Neuberechnung, und hier wird keine ausgeloest. Der Tag
     * muss dort geschlossen werden, wo er geleert wird.
     *
     * Ein Ruhetag ist die ehrliche Darstellung — an dem Tag steht jetzt kein
     * Training. Ein Loch behauptet dagegen gar nichts und sieht in der App
     * aus wie ein Fehler, was es ja auch war.
     */
    private function sealVacatedDay(\App\Models\User $user, string $date, string $why): void
    {
        $stillThere = \App\Models\TrainingSession::where('user_id', $user->id)
            ->whereDate('planned_date', $date)
            ->exists();

        if ($stillThere) {
            return;
        }

        // Ohne aktiven Plan haette der Ruhetag keinen, an dem er haengt — die
        // Planseite laedt nur Einheiten des aktiven Plans, er waere unsichtbar.
        $hasPlan = \App\Models\TrainingPlan::where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();

        if (! $hasPlan) {
            return;
        }

        $session = $this->newSessionFor($user, $date, 'rest');
        $session->description = "Die Einheit dieses Tages wurde {$why}.";
        $session->sort_order  = 0;
        $session->save();
    }

    private function newSessionFor(\App\Models\User $user, string $date, string $type): \App\Models\TrainingSession
    {
        $plan = \App\Models\TrainingPlan::where('user_id', $user->id)
            ->where('is_active', true)
            ->latest()
            ->first();

        $session = new \App\Models\TrainingSession();
        $session->user_id          = $user->id;
        $session->training_plan_id = $plan?->id;
        $session->event_id         = $plan?->event_id;
        $session->planned_date     = $date;
        $session->status           = 'planned';
        $session->sort_order       = $this->isRun($type) ? 1 : 2;
        $session->type             = $type;
        $session->title            = $this->sessionTitleFor($type);
        $session->description      = '';
        $session->intensity        = $this->intensityFor($type);

        return $session;
    }

    /**
     * Die vom Werkzeug gelieferten Felder übernehmen. title, description und
     * intensity sind in der Datenbank Pflicht, stehen aber nicht in jeder
     * Werkzeugbeschreibung — ohne Vorbelegung scheiterte das Speichern, und
     * der Athlet sah nur „Server Error".
     *
     * Die zweite Aufgabe ist wichtiger: dafür sorgen, dass die Einheit in
     * sich stimmt. Gemeldet wurde ein Fall, in dem der Coach den Titel auf
     * „Longrun 25 km" setzte und Distanz und Dauer des alten 7,2-km-Laufs
     * stehenblieben — der Athlet las eine Überschrift, die nichts mit den
     * Zahlen darunter zu tun hatte. Ein Sprachmodell füllt eben nicht
     * zuverlässig alle optionalen Felder. Also wird hier nachgerechnet.
     */
    private function applySessionFields(\App\Models\TrainingSession $session, array $args, ?\App\Models\User $user = null): void
    {
        $given = [];

        foreach (['type', 'title', 'description', 'distance_km', 'duration_min', 'pace_target', 'zone'] as $field) {
            if (array_key_exists($field, $args) && $args[$field] !== null && $args[$field] !== '') {
                $session->{$field} = $args[$field];
                $given[$field]     = true;
            }
        }

        if ($this->isRun($session->type)) {
            $this->reconcileDistanceAndDuration($session, $given, $user);
        }

        $session->title       = $session->title       ?: $this->sessionTitleFor($session->type);
        $session->description = $session->description ?? '';
        $session->intensity   = $session->intensity   ?: $this->intensityFor($session->type);

        // Was ueber den Chat gesetzt wurde, hat der Athlet ausdruecklich
        // bestellt. Beide Plan-Jobs raeumen vor dem Schreiben alles Geplante
        // ab — ohne diese Markierung war ein "ich moechte am Sonntag 25 km
        // laufen" beim naechsten Durchlauf still wieder weg.
        $session->pinned_at = now();

        // Schritteliste und Verpflegungshinweise gehören zur alten Vorgabe.
        $session->steps          = null;
        $session->nutrition_tips = null;
    }

    /**
     * Distanz, Dauer und Pace zueinander passend machen.
     *
     * Nennt der Coach nur eine der beiden Größen, wird die andere aus der
     * Pace des Athleten gerechnet, statt den alten Wert stehenzulassen.
     * Ändert sich der Typ, ohne dass Zahlen mitkommen, sind die alten Zahlen
     * ohnehin falsch — dann werden sie aus der Dauer neu abgeleitet.
     *
     * @param  array<string,bool>  $given  Welche Felder der Coach geliefert hat
     */
    private function reconcileDistanceAndDuration(
        \App\Models\TrainingSession $session,
        array $given,
        ?\App\Models\User $user,
    ): void {
        $paceSec = $this->paceSecondsFor($session->type, $user);

        $hasDistance = isset($given['distance_km']);
        $hasDuration = isset($given['duration_min']);

        if ($hasDistance && ! $hasDuration) {
            $session->duration_min = (int) round($session->distance_km * $paceSec / 60);
        } elseif ($hasDuration && ! $hasDistance) {
            $session->distance_km = round($session->duration_min * 60 / $paceSec, 1);
        } elseif (! $hasDistance && ! $hasDuration && isset($given['type']) && $session->duration_min > 0) {
            // Nur der Typ hat gewechselt: die Dauer bleibt das Verlässliche,
            // die Distanz folgt der neuen Pace.
            $session->distance_km = round($session->duration_min * 60 / $paceSec, 1);
        }

        // Eine Pace aus der alten Einheit passt nach einem Typwechsel nicht
        // mehr. Sie wird dann neu gesetzt statt irrezuführen.
        if (! isset($given['pace_target']) && isset($given['type'])) {
            $session->pace_target = PaceFormat::fromSeconds($paceSec);
        }
    }

    /**
     * Grobes Tempo je Einheitstyp in Sekunden pro Kilometer — als Schnitt
     * über die ganze Einheit, Ein- und Auslaufen eingerechnet. Ohne
     * hinterlegte Schwellenpace wird mit 5:30 gerechnet.
     */
    private function paceSecondsFor(string $type, ?\App\Models\User $user): int
    {
        $threshold = $user?->runnerProfile?->threshold_speed;
        $t         = $threshold > 0 ? $threshold * 60 : 330;

        return (int) round(match ($type) {
            'long_run'         => $t + 70,
            'easy_run'         => $t + 60,
            'progressive_run'  => $t + 45,
            'interval'         => $t + 25,
            'tempo_run'        => $t + 15,
            'test_run'         => $t - 10,
            default            => $t + 40,
        });
    }

    /** „Sonntag, 23.08." — damit die Rückmeldung im Chat lesbar ist. */
    private function dayLabel(string $date): string
    {
        $days = [1 => 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
        $d    = \Carbon\CarbonImmutable::parse($date);

        return $days[$d->isoWeekday()] . ', ' . $d->format('d.m.');
    }

    private function executeCoachTool(\App\Models\User $user, string $toolName, array $args): array
    {
        // Was das Modell tatsaechlich uebergibt, war bisher nirgends
        // nachlesbar. Genau daran haengt aber jede Fehlersuche: der Titel
        // stimmte, die Distanz nicht — ohne den Aufruf im Log bleibt nur
        // Raten, ob das Feld fehlte oder das Speichern.
        \Illuminate\Support\Facades\Log::info('Coach tool call', [
            'user_id' => $user->id,
            'tool'    => $toolName,
            'args'    => $args,
        ]);

        switch ($toolName) {
            case 'remember_user_fact': {
                $fact = trim($args['fact'] ?? '');
                if (!$fact) return ['message' => 'Keine Angabe.', 'action' => null];
                $profile = $user->runnerProfile ?? \App\Models\RunnerProfile::firstOrCreate(['user_id' => $user->id]);
                $profile->rememberNote($fact);
                return ['message' => 'Gespeichert.', 'action' => ['type' => 'memory', 'label' => 'Gemerkt: ' . mb_substr($fact, 0, 80)]];
            }

            // Der alte Name bleibt gueltig: er stand nur fuer heute, und
            // genau daran scheiterte jede Bitte wie "plane mir den Sonntag
            // um". Der Coach antwortete dann so, als haette er es getan.
            case 'modify_today_session':
            case 'modify_training_session': {
                $date = $this->toolDate($args['date'] ?? null);
                if (! $date) {
                    return ['message' => 'Das Datum liegt in der Vergangenheit — vergangene Einheiten aendere ich nicht.', 'action' => null];
                }

                $session = $this->plannedSessionOn($user, $date, $args['type'] ?? null)
                    ?? $this->plannedSessionOn($user, $date);

                if ($session && $session->status === 'completed') {
                    return ['message' => 'Diese Einheit ist bereits absolviert und bleibt, wie sie ist.', 'action' => null];
                }

                if (! $session) {
                    $session = $this->newSessionFor($user, $date, $args['type'] ?? 'easy_run');
                }

                $this->applySessionFields($session, $args, $user);
                $session->save();

                $this->invalidateCoachCaches($user);

                $when = $this->dayLabel($date);
                return [
                    'message' => "Einheit am {$date} aktualisiert: {$session->title}"
                        . ($session->distance_km ? ", {$session->distance_km} km" : '')
                        . ($session->duration_min ? ", {$session->duration_min} min" : '') . '.',
                    'action'  => ['type' => 'session_modified', 'label' => "Training angepasst ({$when}): {$session->title}", 'reload' => true],
                ];
            }

            case 'create_training_session': {
                $date = $this->toolDate($args['date'] ?? null);
                if (! $date) {
                    return ['message' => 'Das Datum liegt in der Vergangenheit — dort lege ich nichts mehr an.', 'action' => null];
                }

                $type = $args['type'] ?? 'easy_run';

                // Ein Lauftraining pro Tag. Steht dort schon ein Lauf, wird er
                // ersetzt statt verdoppelt — dieselbe Regel, nach der auch der
                // Planer arbeitet.
                if ($this->isRun($type)) {
                    $existing = $this->plannedRunOn($user, $date);
                    if ($existing) {
                        $this->applySessionFields($existing, $args, $user);
                        $existing->save();
                        $this->invalidateCoachCaches($user);

                        return [
                            'message' => "An dem Tag stand bereits ein Lauf — er wurde ersetzt statt verdoppelt: {$existing->title}. Pro Tag gibt es nur ein Lauftraining. Sage das dem Athleten.",
                            'action'  => ['type' => 'session_modified', 'label' => "Training angepasst ({$this->dayLabel($date)}): {$existing->title}", 'reload' => true],
                        ];
                    }
                }

                $session = $this->newSessionFor($user, $date, $type);
                $this->applySessionFields($session, $args, $user);
                $session->save();

                $this->invalidateCoachCaches($user);

                return [
                    'message' => "Neue Einheit am {$date} angelegt: {$session->title}"
                        . ($session->distance_km ? ", {$session->distance_km} km" : '')
                        . ($session->duration_min ? ", {$session->duration_min} min" : '') . '.',
                    'action'  => ['type' => 'session_created', 'label' => "Einheit ergaenzt ({$this->dayLabel($date)}): {$session->title}", 'reload' => true],
                ];
            }

            case 'move_training_session': {
                $from = $this->toolDate($args['from_date'] ?? null, allowPast: true);
                $to   = $this->toolDate($args['to_date'] ?? null);

                if (! $from || ! $to) {
                    return ['message' => 'Der Zieltag liegt in der Vergangenheit — dorthin verschiebe ich nichts.', 'action' => null];
                }

                $session = $this->plannedSessionOn($user, $from, $args['type'] ?? null)
                    ?? $this->plannedSessionOn($user, $from);

                if (! $session) {
                    return ['message' => "Am {$from} steht keine geplante Einheit, die ich verschieben koennte.", 'action' => null];
                }

                $replaced = '';
                if ($this->isRun($session->type)) {
                    $clash = $this->plannedRunOn($user, $to);
                    if ($clash && $clash->id !== $session->id) {
                        $clash->delete();
                        $replaced = " Der dort geplante Lauf ({$clash->title}) ist entfallen — pro Tag nur ein Lauftraining.";
                    }
                }

                $session->planned_date   = $to;
                $session->steps          = null;
                $session->nutrition_tips = null;
                $session->pinned_at      = now();
                $session->save();

                // Der Herkunftstag ist jetzt womoeglich leer.
                $this->sealVacatedDay($user, $from, 'verschoben');

                $this->invalidateCoachCaches($user);

                return [
                    'message' => "{$session->title} von {$from} auf {$to} verschoben.{$replaced}",
                    'action'  => ['type' => 'session_moved', 'label' => "Verschoben auf {$this->dayLabel($to)}: {$session->title}", 'reload' => true],
                ];
            }

            case 'delete_training_session': {
                $date = $this->toolDate($args['date'] ?? null, allowPast: true);
                if (! $date) {
                    return ['message' => 'Kein gueltiges Datum.', 'action' => null];
                }

                $query = \App\Models\TrainingSession::where('user_id', $user->id)
                    ->whereDate('planned_date', $date)
                    ->where('status', 'planned');

                if (! empty($args['type'])) {
                    $query->where('type', $args['type']);
                }

                $sessions = $query->get();
                if ($sessions->isEmpty()) {
                    return ['message' => "Am {$date} steht keine geplante Einheit, die ich loeschen koennte.", 'action' => null];
                }

                $titles = $sessions->pluck('title')->implode(', ');
                $count  = $sessions->count();
                $sessions->each->delete();

                $this->sealVacatedDay($user, $date, 'gestrichen');

                $this->invalidateCoachCaches($user);

                return [
                    'message' => "{$count} Einheit(en) am {$date} geloescht: {$titles}.",
                    'action'  => ['type' => 'session_deleted', 'label' => "Geloescht ({$this->dayLabel($date)}): {$titles}", 'reload' => true],
                ];
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
