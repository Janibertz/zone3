<?php

namespace App\Services\Fit;

use ZipArchive;

/**
 * Die FIT-Dateien aus einem Upload: die Datei selbst oder, bei einer ZIP,
 * jede .fit darin (13.11.2026).
 *
 * Garmin Connect exportiert eine Aktivität als ZIP mit der FIT-Datei darin,
 * und am iPhone entpackt kaum jemand vorher. Grenzen gegen eine Zip-Bombe:
 * höchstens MAX_ENTRIES Dateien, jede höchstens MAX_ENTRY_BYTES entpackt,
 * alle zusammen höchstens MAX_TOTAL_BYTES. Verschachtelte ZIPs werden nicht
 * geöffnet.
 */
class FitArchive
{
    public const MAX_ENTRIES     = 200;
    public const MAX_ENTRY_BYTES = 20 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 200 * 1024 * 1024;

    /**
     * @return array{files: list<string>, skipped: int}|null  null = keine lesbare ZIP
     */
    public static function extract(string $path, string $originalName): ?array
    {
        if (! str_ends_with(strtolower($originalName), '.zip')) {
            return ['files' => [(string) file_get_contents($path)], 'skipped' => 0];
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }

        $files   = [];
        $skipped = 0;
        $total   = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');

            if (str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/') || ! str_ends_with(strtolower($name), '.fit')) {
                continue;
            }

            $size = (int) ($stat['size'] ?? 0);
            if (count($files) >= self::MAX_ENTRIES || $size > self::MAX_ENTRY_BYTES || $total + $size > self::MAX_TOTAL_BYTES) {
                $skipped++;
                continue;
            }

            $bytes = $zip->getFromIndex($i, self::MAX_ENTRY_BYTES + 1);
            if ($bytes === false || strlen($bytes) > self::MAX_ENTRY_BYTES) {
                $skipped++;
                continue;
            }

            $total  += strlen($bytes);
            $files[] = $bytes;
        }

        $zip->close();

        return ['files' => $files, 'skipped' => $skipped];
    }
}
