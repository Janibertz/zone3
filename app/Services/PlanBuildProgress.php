<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Wie weit der Planbau gerade ist — für die Schritt-Anzeige auf der
 * Planseite statt eines Ladekreisels (Jan, 10.11.2026, Idee E aus dem
 * Kotcha-Onboarding).
 *
 * Der Job meldet echte Stationen mit echten Zahlen aus dem Kontext; nichts
 * davon ist eine Animation nach Uhrzeit. Liegt im Cache, weil es nur
 * während eines laufenden Baus zählt.
 */
class PlanBuildProgress
{
    public const STAGES = ['start', 'form', 'weeks', 'model', 'check', 'save'];

    private const TTL_MINUTES = 30;

    private static function key(int $eventId): string
    {
        return "plan-progress:{$eventId}";
    }

    /** Neue Station; Fakten früherer Stationen bleiben stehen. */
    public static function mark(int $eventId, string $stage, array $facts = []): void
    {
        $now = self::get($eventId) ?? ['facts' => []];

        Cache::put(self::key($eventId), [
            'stage' => $stage,
            'facts' => array_merge($now['facts'] ?? [], array_filter($facts, fn ($v) => $v !== null)),
        ], now()->addMinutes(self::TTL_MINUTES));
    }

    public static function get(int $eventId): ?array
    {
        return Cache::get(self::key($eventId));
    }

    public static function forget(int $eventId): void
    {
        Cache::forget(self::key($eventId));
    }

    /** Form: VDOT und Prognose gegen das Ziel, so wie sie in den Plan gehen. */
    public static function formFacts(PlanContext $context): array
    {
        $p = $context->paces ?? [];

        return [
            'vdot'      => isset($p['vdot']) ? round((float) $p['vdot'], 1) : null,
            'predicted' => $p['predicted_time'] ?? null,
            'target'    => $p['target_time'] ?? null,
        ];
    }

    /** Gerüst: wie viele Tage, Läufe und harte Einheiten, welche Phase. */
    public static function weekFacts(PlanContext $context): array
    {
        $days  = $context->skeleton['days'] ?? [];
        $runs  = 0;
        $hard  = 0;
        foreach ($days as $day) {
            foreach ($day['slots'] ?? [] as $slot) {
                if (in_array($slot['type'] ?? '', ['rest', 'strength', 'core', 'mobility', 'cross_training'], true)) {
                    continue;
                }
                $runs++;
                if (! empty($slot['hard'])) {
                    $hard++;
                }
            }
        }

        $period = collect($context->skeleton['periods'] ?? [])->first();

        return [
            'days'  => count($days),
            'runs'  => $runs,
            'hard'  => $hard,
            'phase' => $period['label'] ?? null,
            'weeks_to_race' => $period['weeks_to_race'] ?? null,
        ];
    }
}
