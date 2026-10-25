<?php

namespace App\Services\Bike;

/**
 * Eine Radeinheit als .zwo-Datei für Zwift.
 *
 * Zwift liest eigene Workouts aus `Dokumente/Zwift/Workouts/<ID>/` auf einem
 * PC oder Mac; einmal dort abgelegt, ist die Einheit auch auf iPad und
 * Apple TV da. Eine öffentliche Schnittstelle, die Einheiten direkt in den
 * Zwift-Kalender legt, gibt es nicht — die Training API vergibt Zwift nur an
 * ausgewählte Partner.
 *
 * Die Leistung steht in der Datei als Anteil der FTP, nicht in Watt: Zwift
 * rechnet mit der FTP, die dort hinterlegt ist. Eine Wiederholung aus Arbeit
 * und Pause wird ein `IntervalsT`, damit Zwift sie als eine Serie zeigt.
 */
class ZwoWriter
{
    /** @param list<array<string, mixed>> $steps */
    public function write(string $name, string $description, array $steps): string
    {
        $xml  = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('    ');

        $xml->startElement('workout_file');
        $xml->writeElement('author', 'Zone3');
        $xml->writeElement('name', $name);
        $xml->writeElement('description', $description);
        $xml->writeElement('sportType', 'bike');
        $xml->startElement('tags');
        $xml->startElement('tag');
        $xml->writeAttribute('name', 'Zone3');
        $xml->endElement();
        $xml->endElement();

        $xml->startElement('workout');

        $steps = array_values($steps);
        for ($i = 0; $i < count($steps); $i++) {
            $step = $steps[$i];
            $next = $steps[$i + 1] ?? null;
            $reps = (int) ($step['repetitions'] ?? 1);

            // Arbeit + Pause mit derselben Anzahl Wiederholungen → eine Serie.
            if ($reps > 1 && $next && (int) ($next['repetitions'] ?? 1) === $reps) {
                $xml->startElement('IntervalsT');
                $xml->writeAttribute('Repeat', (string) $reps);
                $xml->writeAttribute('OnDuration', (string) $this->seconds($step));
                $xml->writeAttribute('OffDuration', (string) $this->seconds($next));
                $xml->writeAttribute('OnPower', $this->power($this->mid($step)));
                $xml->writeAttribute('OffPower', $this->power($this->mid($next)));
                $xml->endElement();
                $i++;
                continue;
            }

            for ($r = 0; $r < max(1, $reps); $r++) {
                $this->block($xml, $step);
            }
        }

        $xml->endElement(); // workout
        $xml->endElement(); // workout_file

        return $xml->outputMemory();
    }

    private function block(\XMLWriter $xml, array $step): void
    {
        $type = $step['type'] ?? 'work';
        $low  = (float) ($step['power_low'] ?? 0.6);
        $high = (float) ($step['power_high'] ?? $low);

        // Ein- und Ausfahren steigen bzw. fallen — dafür hat Zwift eigene
        // Blöcke. Alles andere ist ein gleichmäßiger Abschnitt in der Mitte
        // des Bereichs.
        if ($type === 'warmup' || $type === 'cooldown') {
            $xml->startElement($type === 'warmup' ? 'Warmup' : 'Cooldown');
            $xml->writeAttribute('Duration', (string) $this->seconds($step));
            $xml->writeAttribute('PowerLow', $this->power($low));
            $xml->writeAttribute('PowerHigh', $this->power($high));
            $xml->endElement();

            return;
        }

        $xml->startElement('SteadyState');
        $xml->writeAttribute('Duration', (string) $this->seconds($step));
        $xml->writeAttribute('Power', $this->power(($low + $high) / 2));
        $xml->endElement();
    }

    private function seconds(array $step): int
    {
        return max(1, (int) round(((float) ($step['duration_min'] ?? 1)) * 60));
    }

    private function mid(array $step): float
    {
        $low = (float) ($step['power_low'] ?? 0.6);

        return ($low + (float) ($step['power_high'] ?? $low)) / 2;
    }

    private function power(float $share): string
    {
        return number_format($share, 2, '.', '');
    }
}
