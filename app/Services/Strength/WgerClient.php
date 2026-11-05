<?php

namespace App\Services\Strength;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * wger.de — freie Übungsdatenbank, nur aus dem Admin angesprochen.
 *
 * Der Athlet sieht nie eine wger-Anfrage: Bilder werden beim Import
 * heruntergeladen, verkleinert und in der Datenbank gespeichert.
 *
 * Die Suche der API filtert nicht nach Namen (getestet 04.11.2026: der
 * Suchendpunkt antwortet 404, `name=` wird ignoriert). Deshalb wird einmal
 * die ganze Übungsliste geholt (rund zehn Seiten), zu einem kleinen Index
 * verdichtet und eine Woche gemerkt; gesucht wird darin.
 */
class WgerClient
{
    public const BASE = 'https://wger.de/api/v2';

    public const CACHE_KEY = 'wger:index:v1';

    public const LICENSES = [
        1 => 'CC-BY-SA 3.0',
        2 => 'CC-BY-SA 4.0',
        3 => 'CC0',
        4 => 'CC-BY 4.0',
        5 => 'ODbL',
    ];

    private const LANG_DE = 1;

    private const LANG_EN = 2;

    private const MAX_PIXELS = 640;

    /**
     * @return list<array{id:int, name:string, name_en:?string, category:?string, equipment:list<string>, image:?array}>
     */
    public function index(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addDays(7), function () {
            $out = [];
            $url = self::BASE.'/exerciseinfo/?limit=100&offset=0';

            for ($page = 0; $url && $page < 20; $page++) {
                $res = Http::timeout(30)->acceptJson()->get($url)->throw()->json();
                foreach ($res['results'] ?? [] as $r) {
                    $out[] = $this->compact($r);
                }
                $url = $res['next'] ?? null;
            }

            return array_values(array_filter($out, fn ($e) => $e['name'] !== ''));
        });
    }

    /** Treffer zuerst mit Bild, dann nach Namen. */
    public function search(string $query, int $limit = 30): array
    {
        $q = $this->simplify($query);
        if (mb_strlen($q) < 2) {
            return [];
        }

        return collect($this->index())
            ->filter(fn ($e) => str_contains($this->simplify($e['name']), $q) || str_contains($this->simplify((string) $e['name_en']), $q))
            ->sortBy(fn ($e) => [$e['image'] ? 0 : 1, mb_strlen($e['name'])])
            ->take($limit)
            ->values()
            ->all();
    }

    public function find(int $id): ?array
    {
        return collect($this->index())->firstWhere('id', $id);
    }

    /**
     * Hauptbild einer Übung holen und verkleinern.
     *
     * @return array{mime:string, data:string, license:?string, author:?string}|null
     */
    public function downloadImage(int $wgerId): ?array
    {
        $image = $this->find($wgerId)['image'] ?? null;
        if (! $image) {
            return null;
        }

        $bytes = Http::timeout(30)->get($image['url'])->throw()->body();
        $jpeg = self::resize($bytes);
        if ($jpeg === null) {
            return null;
        }

        return [
            'mime' => 'image/jpeg',
            'data' => base64_encode($jpeg),
            'license' => $image['license'],
            'author' => $image['author'],
        ];
    }

    /**
     * Auf höchstens 640 px verkleinern, als JPEG auf weißem Grund (viele
     * wger-Bilder sind transparente PNGs). Sonst unverändert — die Bilder
     * stehen unter CC-BY-SA, eine Bearbeitung müsste man kennzeichnen.
     */
    public static function resize(string $bytes): ?string
    {
        $src = @imagecreatefromstring($bytes);
        if (! $src) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, self::MAX_PIXELS / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagejpeg($dst, null, 82);
        $out = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        return $out ?: null;
    }

    private function compact(array $r): array
    {
        $name = fn (int $lang) => collect($r['translations'] ?? [])->firstWhere('language', $lang)['name'] ?? null;

        $images = collect($r['images'] ?? []);
        $main = $images->firstWhere('is_main', true) ?? $images->first();

        return [
            'id' => (int) $r['id'],
            'name' => trim((string) ($name(self::LANG_DE) ?? $name(self::LANG_EN) ?? '')),
            'name_en' => $name(self::LANG_EN),
            'category' => $r['category']['name'] ?? null,
            'equipment' => collect($r['equipment'] ?? [])->pluck('name')->values()->all(),
            'image' => $main ? [
                'url' => $main['image'],
                'thumb' => $main['thumbnails']['small'] ?? $main['image'],
                'license' => self::LICENSES[$main['license'] ?? 0] ?? null,
                'author' => trim((string) ($main['license_author'] ?? '')) ?: null,
                'ai' => (bool) ($main['is_ai_generated'] ?? false),
            ] : null,
        ];
    }

    private function simplify(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($s)));
    }
}
