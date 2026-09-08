<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Die letzten Zeilen aus dem Anwendungslog — lesbar, ohne Container.
 *
 * Der Anlass: dreimal in einer Woche hing eine Fehlersuche daran, dass die
 * Antwort im Log stand und niemand drankam. „Ruft Strava ueberhaupt an?"
 * hat zwei lange Sitzungen gekostet und war die ganze Zeit eine Zeile
 * entfernt.
 *
 * Zwei Dinge, auf die es beim Lesen einer Logdatei ankommt:
 *
 *  · **Nie ganz einlesen.** Die Datei waechst unbegrenzt; ein
 *    `file_get_contents` auf zweihundert Megabyte legt den Webserver
 *    lahm — dieselbe Sorte Fehler, die schon der synchrone Webhook war.
 *    Gelesen wird ein Fenster vom Ende her.
 *  · **Ein Eintrag ist nicht eine Zeile.** Ein Stacktrace bringt hundert
 *    Folgezeilen mit. Sie gehoeren zum Eintrag davor, nicht daneben.
 */
class LogReader
{
    /** Wie viel vom Ende der Datei gelesen wird. */
    public const WINDOW_BYTES = 512 * 1024;

    /** Erste Zeile eines Eintrags: [Datum] umgebung.LEVEL: Text */
    private const HEAD = '/^\[(?<time>\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\]\s+(?<env>\S+?)\.(?<level>[A-Z]+):\s?(?<message>.*)$/';

    /**
     * Welche Datei gelesen wird.
     *
     * `single` schreibt `laravel.log`, `daily` haengt das Datum an. Genommen
     * wird die zuletzt geschriebene — sonst zeigt die Ansicht nach einer
     * Umstellung stillschweigend eine alte Datei.
     */
    public function file(): ?string
    {
        $files = glob(storage_path('logs/laravel*.log')) ?: [];

        if ($files === []) {
            return null;
        }

        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $files[0];
    }

    /**
     * Die letzten Eintraege, neueste zuerst.
     *
     * @return array{file: ?string, size: int, entries: list<array<string, mixed>>, truncated: bool}
     */
    public function tail(?string $search = null, ?string $level = null, int $limit = 200): array
    {
        $file = $this->file();

        if (! $file || ! is_readable($file)) {
            return ['file' => $file, 'size' => 0, 'entries' => [], 'truncated' => false];
        }

        $size  = filesize($file);
        $start = max(0, $size - self::WINDOW_BYTES);

        $handle = fopen($file, 'rb');
        fseek($handle, $start);
        $chunk = stream_get_contents($handle);
        fclose($handle);

        // Beim Anschneiden faellt die erste, halbe Zeile weg.
        if ($start > 0) {
            $chunk = substr($chunk, (int) strpos($chunk, "\n") + 1);
        }

        $entries = $this->parse($chunk);

        if ($level) {
            $entries = $entries->where('level', strtoupper($level));
        }

        if ($search) {
            $needle  = mb_strtolower($search);
            $entries = $entries->filter(fn ($e) => str_contains(mb_strtolower($e['message'] . ' ' . $e['context']), $needle));
        }

        return [
            'file'      => basename($file),
            'size'      => $size,
            'entries'   => $entries->reverse()->take($limit)->values()->all(),
            'truncated' => $start > 0,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function parse(string $chunk): Collection
    {
        $entries = collect();
        $current = null;

        foreach (explode("\n", $chunk) as $line) {
            if (preg_match(self::HEAD, $line, $m)) {
                if ($current) {
                    $entries->push($current);
                }

                // Der Kontext haengt als JSON hinten dran. Er ist die
                // eigentliche Information — owner_id, user_id, strava_id.
                $message = $m['message'];
                $context = '';

                if (($brace = strpos($message, ' {')) !== false) {
                    $context = substr($message, $brace + 1);
                    $message = substr($message, 0, $brace);
                }

                $current = [
                    'time'    => $m['time'],
                    'level'   => $m['level'],
                    'message' => trim($message),
                    'context' => mb_substr(trim($context), 0, 600),
                    'trace'   => 0,
                ];

                continue;
            }

            // Folgezeile — Stacktrace. Sie gehoert zum Eintrag davor.
            if ($current && trim($line) !== '') {
                $current['trace']++;
            }
        }

        if ($current) {
            $entries->push($current);
        }

        return $entries;
    }
}
