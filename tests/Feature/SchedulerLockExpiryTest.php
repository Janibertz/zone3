<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Eine Sperre gegen Ueberlappen darf einen abgebrochenen Lauf nicht um einen
 * ganzen Tag ueberleben.
 *
 * Am 22.11.2026 kam `strava:sync` um 19:00 nie zum Ende. Die Sperre von
 * `withoutOverlapping()` liegt im Datenbank-Cache und gilt ohne Angabe
 * 24 Stunden — bis zum naechsten Abend lief kein Abgleich, und der Waechter
 * meldete „lief zuletzt vor 1124 Minuten".
 */
class SchedulerLockExpiryTest extends TestCase
{
    public function test_frequent_commands_release_a_stale_lock_within_a_few_hours(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => $e->withoutOverlapping && ! str_contains($e->command ?? '', 'perf:watch'));

        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $this->assertLessThanOrEqual(
                180,
                $event->expiresAt,
                "Sperre zu lang: {$event->command} ({$event->expiresAt} min)",
            );
        }
    }

    public function test_strava_sync_lock_expires_after_an_hour(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command ?? '', 'strava:sync'));

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(60, $event->expiresAt);
    }
}
