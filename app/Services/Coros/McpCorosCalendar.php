<?php

namespace App\Services\Coros;

use App\Models\CorosConnection;

/**
 * Der COROS-Kalender ueber MCP: `createScheduledWorkout` und
 * `updateScheduledWorkout`. Datum als `yyyyMMdd` in der Zeitzone des
 * COROS-Kontos, heute bis 90 Tage voraus.
 */
class McpCorosCalendar implements CorosCalendar
{
    public function __construct(private readonly CorosClient $client) {}

    public function create(CorosConnection $connection, string $date, array $course): string
    {
        $text = $this->client->callTool($connection, 'createScheduledWorkout', [
            'date'   => self::day($date),
            'course' => $course,
        ]);

        // Die Antwort ist Text: „Workout created on 20261106: … idInPlan: 1“.
        if (! preg_match('/idInPlan:\s*(\S+)/', $text, $m)) {
            throw new CorosException('COROS createScheduledWorkout: keine idInPlan in der Antwort: ' . mb_substr($text, 0, 200));
        }

        return trim($m[1], '".,');
    }

    public function replace(CorosConnection $connection, string $date, string $idInPlan, array $course): void
    {
        $this->client->callTool($connection, 'updateScheduledWorkout', [
            'date'     => self::day($date),
            'idInPlan' => $idInPlan,
            'course'   => $course,
        ]);
    }

    private static function day(string $date): string
    {
        return str_replace('-', '', substr($date, 0, 10));
    }
}
