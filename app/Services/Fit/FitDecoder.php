<?php

namespace App\Services\Fit;

use RuntimeException;

/**
 * Liest die Teile einer FIT-Datei, die Zone3 braucht: Messpunkte (record),
 * Runden (lap) und die Zusammenfassung (session).
 *
 * Kein vollstaendiger Decoder, sondern das FIT-Protokoll ohne Profil: jede
 * Nachricht wird nach ihrer Definition gelesen, behalten werden nur die
 * bekannten Felder. Entwicklerfelder und alle anderen Nachrichten werden
 * uebersprungen. Werte in Rohform werden hier schon umgerechnet (Meter,
 * m/s, Grad, Unix-Sekunden); ungueltige Werte (0xFF…) fallen weg.
 *
 * Gebraucht fuer COROS (MCP liefert die Messpunkte nur als FIT-Datei),
 * geschrieben nach dem FIT-Protokoll 2.0 (Garmin FIT SDK).
 */
class FitDecoder
{
    /** FIT zaehlt ab 31.12.1989 00:00 UTC. */
    private const FIT_EPOCH = 631065600;

    private const MSG_SESSION = 18;
    private const MSG_LAP = 19;
    private const MSG_RECORD = 20;

    /** Je Nachricht: Feldnummer => [Name, Faktor, Versatz]; Wert = roh / Faktor - Versatz. */
    private const FIELDS = [
        self::MSG_RECORD => [
            253 => ['timestamp', 1, 0],
            0   => ['lat', 'semicircle', 0],
            1   => ['lng', 'semicircle', 0],
            2   => ['altitude', 5, 500],
            78  => ['enhanced_altitude', 5, 500],
            3   => ['heart_rate', 1, 0],
            4   => ['cadence', 1, 0],
            5   => ['distance', 100, 0],
            6   => ['speed', 1000, 0],
            73  => ['enhanced_speed', 1000, 0],
            7   => ['power', 1, 0],
        ],
        self::MSG_LAP => [
            253 => ['timestamp', 1, 0],
            2   => ['start_time', 1, 0],
            7   => ['total_elapsed_time', 1000, 0],
            8   => ['total_timer_time', 1000, 0],
            9   => ['total_distance', 100, 0],
            13  => ['avg_speed', 1000, 0],
            14  => ['max_speed', 1000, 0],
            110 => ['enhanced_avg_speed', 1000, 0],
            111 => ['enhanced_max_speed', 1000, 0],
            15  => ['avg_heart_rate', 1, 0],
            16  => ['max_heart_rate', 1, 0],
        ],
        self::MSG_SESSION => [
            253 => ['timestamp', 1, 0],
            2   => ['start_time', 1, 0],
            5   => ['sport', 1, 0],
            6   => ['sub_sport', 1, 0],
            7   => ['total_elapsed_time', 1000, 0],
            8   => ['total_timer_time', 1000, 0],
            9   => ['total_distance', 100, 0],
            14  => ['avg_speed', 1000, 0],
            15  => ['max_speed', 1000, 0],
            124 => ['enhanced_avg_speed', 1000, 0],
            125 => ['enhanced_max_speed', 1000, 0],
            16  => ['avg_heart_rate', 1, 0],
            17  => ['max_heart_rate', 1, 0],
            20  => ['avg_power', 1, 0],
            34  => ['normalized_power', 1, 0],
            22  => ['total_ascent', 1, 0],
            23  => ['total_descent', 1, 0],
        ],
    ];

    /** Zeitfelder, die in Unix-Sekunden umgerechnet werden. */
    private const TIME_FIELDS = ['timestamp', 'start_time'];

    /** Basistyp (untere 5 Bit) => [Groesse, unpack-Code LE, unpack-Code BE, ungueltiger Wert]. */
    private const BASE_TYPES = [
        0  => [1, 'C', 'C', 0xFF],         // enum
        1  => [1, 'c', 'c', 0x7F],         // sint8
        2  => [1, 'C', 'C', 0xFF],         // uint8
        3  => [2, 's', 's', 0x7FFF],       // sint16 (Byte-Reihenfolge von Hand)
        4  => [2, 'v', 'n', 0xFFFF],       // uint16
        5  => [4, 'l', 'l', 0x7FFFFFFF],   // sint32 (Byte-Reihenfolge von Hand)
        6  => [4, 'V', 'N', 0xFFFFFFFF],   // uint32
        10 => [1, 'C', 'C', 0x00],         // uint8z
        11 => [2, 'v', 'n', 0x0000],       // uint16z
        12 => [4, 'V', 'N', 0x00000000],   // uint32z
    ];

    /**
     * @return array{records: list<array>, laps: list<array>, session: array}
     */
    public function decode(string $bytes): array
    {
        if (strlen($bytes) < 12 || substr($bytes, 8, 4) !== '.FIT') {
            throw new RuntimeException('Keine FIT-Datei.');
        }

        $headerSize = ord($bytes[0]);
        $dataSize   = unpack('V', substr($bytes, 4, 4))[1];
        $end        = min(strlen($bytes), $headerSize + $dataSize);
        $pos        = $headerSize;

        $definitions   = [];
        $lastTimestamp = 0;
        $out           = ['records' => [], 'laps' => [], 'session' => []];

        while ($pos < $end) {
            $header = ord($bytes[$pos++]);

            // Komprimierter Zeitstempel: Datennachricht mit 5-Bit-Zeitversatz.
            if ($header & 0x80) {
                $local  = ($header >> 5) & 0x03;
                $offset = $header & 0x1F;
                $lastTimestamp = ($lastTimestamp & ~0x1F) + $offset + ($offset >= ($lastTimestamp & 0x1F) ? 0 : 0x20);
                $message = $this->readData($bytes, $pos, $definitions[$local] ?? throw new RuntimeException('Unbekannte Definition.'));
                $message['fields'][253] ??= $lastTimestamp;
                $this->collect($out, $message);

                continue;
            }

            $local = $header & 0x0F;

            if ($header & 0x40) {
                $definitions[$local] = $this->readDefinition($bytes, $pos, (bool) ($header & 0x20));

                continue;
            }

            $message = $this->readData($bytes, $pos, $definitions[$local] ?? throw new RuntimeException('Unbekannte Definition.'));
            if (isset($message['fields'][253]) && is_int($message['fields'][253])) {
                $lastTimestamp = $message['fields'][253];
            }
            $this->collect($out, $message);
        }

        return $out;
    }

    private function readDefinition(string $bytes, int &$pos, bool $developer): array
    {
        $bigEndian = ord($bytes[$pos + 1]) === 1;
        $global    = unpack($bigEndian ? 'n' : 'v', substr($bytes, $pos + 2, 2))[1];
        $count     = ord($bytes[$pos + 4]);
        $pos      += 5;

        $fields = [];
        for ($i = 0; $i < $count; $i++) {
            $fields[] = [ord($bytes[$pos]), ord($bytes[$pos + 1]), ord($bytes[$pos + 2]) & 0x1F];
            $pos += 3;
        }

        $devBytes = 0;
        if ($developer) {
            $devCount = ord($bytes[$pos++]);
            for ($i = 0; $i < $devCount; $i++) {
                $devBytes += ord($bytes[$pos + 1]);
                $pos += 3;
            }
        }

        return ['global' => $global, 'big' => $bigEndian, 'fields' => $fields, 'dev_bytes' => $devBytes];
    }

    private function readData(string $bytes, int &$pos, array $definition): array
    {
        $values = [];

        foreach ($definition['fields'] as [$num, $size, $base]) {
            $raw  = substr($bytes, $pos, $size);
            $pos += $size;

            if (isset(self::FIELDS[$definition['global']][$num])) {
                $value = $this->value($raw, $base, $definition['big']);
                if ($value !== null) {
                    $values[$num] = $value;
                }
            }
        }

        $pos += $definition['dev_bytes'];

        return ['global' => $definition['global'], 'fields' => $values];
    }

    /** Erstes Element eines Felds als Zahl, null wenn ungueltig oder kein Zahlentyp. */
    private function value(string $raw, int $base, bool $bigEndian): ?int
    {
        $type = self::BASE_TYPES[$base] ?? null;
        if (! $type || strlen($raw) < $type[0]) {
            return null;
        }

        $chunk = substr($raw, 0, $type[0]);
        // Vorzeichenbehaftete 16/32 Bit kennt unpack nur in Maschinenreihenfolge.
        if (in_array($base, [3, 5], true)) {
            $unsigned = unpack($bigEndian ? ($base === 3 ? 'n' : 'N') : ($base === 3 ? 'v' : 'V'), $chunk)[1];
            $bits     = $base === 3 ? 16 : 32;
            if ($unsigned === $type[3]) {
                return null;
            }

            return $unsigned >= (1 << ($bits - 1)) ? $unsigned - (1 << $bits) : $unsigned;
        }

        $value = unpack($bigEndian ? $type[2] : $type[1], $chunk)[1];

        return $value === $type[3] ? null : $value;
    }

    private function collect(array &$out, array $message): void
    {
        $map = self::FIELDS[$message['global']] ?? null;
        if (! $map) {
            return;
        }

        $named = [];
        foreach ($message['fields'] as $num => $raw) {
            [$name, $scale, $offset] = $map[$num];
            $named[$name] = match (true) {
                in_array($name, self::TIME_FIELDS, true) => $raw + self::FIT_EPOCH,
                $scale === 'semicircle'                  => $raw * (180 / 2147483648),
                default                                  => $scale === 1 && $offset === 0 ? $raw : $raw / $scale - $offset,
            };
        }

        // Die „enhanced“-Felder sind genauer und gewinnen.
        foreach (['speed', 'altitude', 'avg_speed', 'max_speed'] as $field) {
            if (isset($named["enhanced_{$field}"])) {
                $named[$field] = $named["enhanced_{$field}"];
            }
            unset($named["enhanced_{$field}"]);
        }

        match ($message['global']) {
            self::MSG_RECORD  => $out['records'][] = $named,
            self::MSG_LAP     => $out['laps'][] = $named,
            self::MSG_SESSION => $out['session'] = $out['session'] ?: $named,
        };
    }
}
