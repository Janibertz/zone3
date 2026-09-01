<?php

namespace Tests\Unit;

use App\Services\PaceFormat;
use PHPUnit\Framework\TestCase;

/**
 * Die eine Pace-Umrechnung.
 *
 * Sie stand in dreiundzwanzig Kopien im Projekt, und sie rechneten nicht
 * gleich: die meisten schnitten ab, das Coach-Review rundete. Für denselben
 * Lauf stand damit auf der einen Seite 5:59 und auf der anderen 6:00.
 */
class PaceFormatTest extends TestCase
{
    // ── Sekunden je Kilometer ────────────────────────────────────────────

    public function test_whole_seconds_come_out_unchanged(): void
    {
        $this->assertSame('5:00', PaceFormat::fromSeconds(300));
        $this->assertSame('4:22', PaceFormat::fromSeconds(262));
        $this->assertSame('12:05', PaceFormat::fromSeconds(725));
    }

    /**
     * Erst runden, dann teilen.
     *
     * Andersherum — Minute abschneiden, Sekunde runden — lief die Minute
     * daneben, sobald der Wert knapp unter einer vollen lag: 359,97 ergab
     * „5:00" statt 6:00. Genau dieser Fehler stand im Coach-Review.
     */
    public function test_a_value_just_below_a_full_minute_keeps_its_minute(): void
    {
        $this->assertSame('6:00', PaceFormat::fromSeconds(359.97));
        $this->assertSame('6:00', PaceFormat::fromSeconds(360.0));
        $this->assertSame('5:59', PaceFormat::fromSeconds(359.4));
    }

    public function test_seconds_are_padded(): void
    {
        $this->assertSame('5:07', PaceFormat::fromSeconds(307), 'Aus 5:07 darf nicht „5:7" werden');
    }

    // ── Zielpace: abrunden, nicht runden ─────────────────────────────────

    /**
     * Der Unterschied ist keine Kosmetik. 3:30 Std auf 42,195 km sind
     * 298,6 s/km. Gerundet stünde dort 4:59 — wer das läuft, kommt auf
     * 3:30:22 und verfehlt sein Ziel.
     */
    public function test_a_target_pace_rounds_down(): void
    {
        $marathonSeconds = (3 * 3600 + 30 * 60) / 42.195;

        $this->assertSame('4:58', PaceFormat::target($marathonSeconds));
        $this->assertSame('4:59', PaceFormat::fromSeconds($marathonSeconds), 'Als Messwert wäre 4:59 richtig');
    }

    public function test_a_target_pace_on_a_whole_second_is_unchanged(): void
    {
        $this->assertSame('5:00', PaceFormat::target(300.0));
    }

    // ── Dezimalminuten (threshold_speed) ─────────────────────────────────

    public function test_decimal_minutes_convert(): void
    {
        $this->assertSame('5:30', PaceFormat::fromMinutes(5.5));
        $this->assertSame('4:22', PaceFormat::fromMinutes(4 + 22 / 60));
    }

    /**
     * Mehrere Kopien rechneten die Sekunden getrennt und rundeten sie —
     * damit konnte „4:60" entstehen, weil die Minute nicht mitzählte.
     */
    public function test_no_sixty_second_pace(): void
    {
        $this->assertSame('5:00', PaceFormat::fromMinutes(4.99999));

        foreach (range(0, 200) as $i) {
            $pace = PaceFormat::fromMinutes(4 + $i / 200);
            $this->assertStringEndsNotWith(':60', $pace, "Ungueltige Pace {$pace}");
        }
    }

    // ── Geschwindigkeit ──────────────────────────────────────────────────

    public function test_speed_converts_to_pace(): void
    {
        $this->assertSame('5:00', PaceFormat::fromSpeed(1000 / 300));
        $this->assertSame('3:20', PaceFormat::fromSpeed(5.0));
    }

    // ── Kein Wert ist kein Absturz ───────────────────────────────────────

    public function test_missing_values_give_a_dash(): void
    {
        foreach ([null, 0, -1, 0.0] as $bad) {
            $this->assertSame(PaceFormat::NONE, PaceFormat::fromSeconds($bad));
            $this->assertSame(PaceFormat::NONE, PaceFormat::fromSpeed($bad));
            $this->assertSame(PaceFormat::NONE, PaceFormat::fromMinutes($bad));
            $this->assertSame(PaceFormat::NONE, PaceFormat::target($bad));
        }
    }

    public function test_infinity_does_not_slip_through(): void
    {
        $this->assertSame(PaceFormat::NONE, PaceFormat::fromSeconds(INF));
        $this->assertSame(PaceFormat::NONE, PaceFormat::target(INF));
    }

    // ── Gesamtzeiten ─────────────────────────────────────────────────────

    public function test_hms(): void
    {
        $this->assertSame('01:00:00', PaceFormat::hms(3600));
        $this->assertSame('00:42:07', PaceFormat::hms(2527));
    }
}
