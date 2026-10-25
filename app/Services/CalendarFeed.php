<?php

namespace App\Services;

use App\Models\Event;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Der Trainingsplan als Kalender-Abo (RFC 5545).
 *
 * Entscheidung von Jan (25.10.2026): geplante Einheiten und Rennen,
 * ganztägig. Eine Uhrzeit gibt es nicht — das Wochenraster kennt nur
 * Minuten pro Tag, und eine erfundene Uhrzeit wäre schlechter als keine.
 *
 * - Einheiten des aktiven Plans, ohne Ruhetage (ein Termin „Ruhetag" an
 *   jedem dritten Tag ist Rauschen) und ohne ausgelassene. Erledigte bleiben
 *   mit ✓ stehen, sonst bekäme der Kalender rückwirkend Löcher.
 * - Alle Rennen des Athleten.
 * - Die UID hängt an der Datenbank-ID: verschiebt der Coach eine Einheit,
 *   wandert der Termin, statt doppelt zu stehen. Eine Neuberechnung legt neue
 *   Einheiten an, die alten verschwinden beim nächsten Abruf aus dem Feed.
 */
class CalendarFeed
{
    /** So weit reichen erledigte Einheiten zurück. */
    public const PAST_DAYS = 60;

    /** Rennen dürfen länger zurückliegen — sie sind selten und bleiben wichtig. */
    public const PAST_RACE_DAYS = 365;

    public function build(User $user): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Zone3//Trainingsplan//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:Zone3 Training',
            'X-WR-TIMEZONE:' . config('app.timezone'),
            // Apple und Outlook lesen das eine, Google keines von beiden —
            // Google ruft in eigenem Takt ab (mehrere Stunden).
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
        ];

        foreach ($this->sessions($user) as $session) {
            array_push($lines, ...$this->sessionEvent($session));
        }

        foreach ($this->races($user) as $event) {
            array_push($lines, ...$this->raceEvent($event));
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([$this, 'fold'], $lines)) . "\r\n";
    }

    /** @return Collection<int, TrainingSession> */
    public function sessions(User $user): Collection
    {
        $plan = $user->activeTrainingPlan;

        if (! $plan) {
            return collect();
        }

        return TrainingSession::where('user_id', $user->id)
            ->where('training_plan_id', $plan->id)
            ->where('type', '!=', 'rest')
            ->where('status', '!=', 'skipped')
            ->whereDate('planned_date', '>=', now()->subDays(self::PAST_DAYS)->toDateString())
            ->orderBy('planned_date')
            ->orderBy('sort_order')
            ->get();
    }

    /** @return Collection<int, Event> */
    public function races(User $user): Collection
    {
        return $user->events()
            ->whereDate('event_date', '>=', now()->subDays(self::PAST_RACE_DAYS)->toDateString())
            ->orderBy('event_date')
            ->get();
    }

    private function sessionEvent(TrainingSession $session): array
    {
        $title = $session->title ?: (TrainingSession::TYPE_LABELS[$session->type] ?? $session->type);
        if ($session->status === 'completed') {
            $title = '✓ ' . $title;
        }

        $facts = array_filter([
            $session->distance_km ? $this->km($session->distance_km) : null,
            $session->duration_min ? $session->duration_min . ' min' : null,
            $session->pace_target ? 'Pace ' . $this->pace($session->pace_target) : null,
            $session->zone ? 'Zone ' . $session->zone : null,
        ]);

        $description = implode("\n\n", array_filter([
            implode(' · ', $facts),
            trim((string) $session->description),
            $session->event_id ? route('events.plan.show', $session->event_id) : null,
        ]));

        return $this->allDay(
            uid: "session-{$session->id}@zone3",
            date: $session->planned_date,
            summary: $title,
            description: $description,
            stamp: $session->updated_at,
        );
    }

    private function raceEvent(Event $event): array
    {
        $facts = array_filter([
            $event->distance_label,
            $event->target_time_formatted ? 'Ziel ' . $event->target_time_formatted : null,
            $event->priority ? $event->priority . '-Rennen' : null,
        ]);

        return $this->allDay(
            uid: "race-{$event->id}@zone3",
            date: $event->event_date,
            summary: '🏁 ' . $event->name,
            description: implode("\n\n", array_filter([implode(' · ', $facts), trim((string) $event->notes)])),
            stamp: $event->updated_at,
        );
    }

    private function allDay(string $uid, $date, string $summary, string $description, $stamp): array
    {
        $day = CarbonImmutable::parse($date->toDateString());

        return array_filter([
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . CarbonImmutable::parse($stamp ?? now())->utc()->format('Ymd\THis\Z'),
            'DTSTART;VALUE=DATE:' . $day->format('Ymd'),
            'DTEND;VALUE=DATE:' . $day->addDay()->format('Ymd'),
            'SUMMARY:' . $this->escape($summary),
            $description !== '' ? 'DESCRIPTION:' . $this->escape($description) : null,
            // Ganztägig und „frei": ein Training blockiert keine Besprechung.
            'TRANSP:TRANSPARENT',
            'END:VEVENT',
        ]);
    }

    private function km(float $km): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($km, 1, '.', ''), '0'), '.')) . ' km';
    }

    /** Das Modell schreibt die Einheit manchmal selbst mit hinein. */
    private function pace(string $pace): string
    {
        return str_contains($pace, '/km') ? $pace : $pace . ' min/km';
    }

    private function escape(string $text): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n'],
            $text,
        );
    }

    /**
     * Zeilen höchstens 75 Oktette, Fortsetzung mit führendem Leerzeichen.
     * mb_strcut trennt nie mitten in einem UTF-8-Zeichen — „ü" und „✓"
     * wären sonst zerbrochen.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $parts = [];
        $limit = 75;

        while (strlen($line) > $limit) {
            $chunk   = mb_strcut($line, 0, $limit, 'UTF-8');
            $parts[] = $chunk;
            $line    = substr($line, strlen($chunk));
            $limit   = 74; // das führende Leerzeichen zählt mit
        }

        $parts[] = $line;

        return implode("\r\n ", $parts);
    }
}
