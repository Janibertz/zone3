<?php

namespace App\Services\Content;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Ratgeber und Blog auf zone3.run (seit 11.11.2026, Jan: „Ratgeber und Blog
 * über Neuerungen von Zone3“).
 *
 * Ein Beitrag ist eine Markdown-Datei in `resources/content/<art>/<slug>.md`
 * mit einem Kopf aus `schlüssel: wert`-Zeilen zwischen `---`. Kein Admin,
 * keine Tabelle: Beiträge kommen per Commit, wie der Code. Ein Datum in der
 * Zukunft hält einen Beitrag zurück, bis der Tag da ist.
 */
class Articles
{
    public const KINDS = [
        'ratgeber' => [
            'title' => 'Ratgeber',
            'lead'  => 'Training, Ausrüstung und alles, was dich als Läufer weiterbringt. Ehrlich, ohne Fachchinesisch.',
        ],
        'blog' => [
            'title' => 'Neu in Zone3',
            'lead'  => 'Was sich in Zone3 getan hat und warum. Aus der Werkstatt, Woche für Woche.',
        ],
    ];

    /** @return Collection<int, array> neueste zuerst, nur veröffentlichte */
    public static function all(string $kind): Collection
    {
        $dir = resource_path("content/{$kind}");
        if (! isset(self::KINDS[$kind]) || ! File::isDirectory($dir)) {
            return collect();
        }

        return collect(File::files($dir))
            ->filter(fn ($f) => $f->getExtension() === 'md')
            ->map(fn ($f) => self::parse($kind, $f->getFilenameWithoutExtension(), $f->getContents(), $f->getMTime()))
            ->filter(fn ($a) => $a['date']->lte(today()))
            ->sortByDesc(fn ($a) => $a['date']->timestamp)
            ->values();
    }

    public static function find(string $kind, string $slug): ?array
    {
        return self::all($kind)->firstWhere('slug', $slug);
    }

    public static function parse(string $kind, string $slug, string $raw, int $mtime = 0): array
    {
        $meta = [];
        $body = $raw;
        if (preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $raw, $m)) {
            foreach (preg_split('/\R/', $m[1]) as $line) {
                if (str_contains($line, ':')) {
                    [$k, $v] = array_map('trim', explode(':', $line, 2));
                    $meta[$k] = trim($v, "\"'");
                }
            }
            $body = $m[2];
        }

        $html = Cache::remember("article:{$kind}:{$slug}:{$mtime}", now()->addDay(),
            fn () => (string) Str::markdown($body, ['html_input' => 'strip', 'allow_unsafe_links' => false]));

        $words = str_word_count(strip_tags($html), 0, 'ÄÖÜäöüß');

        return [
            'kind'        => $kind,
            'slug'        => $slug,
            'title'       => $meta['title'] ?? Str::headline($slug),
            'description' => $meta['description'] ?? '',
            'date'        => Carbon::parse($meta['date'] ?? '2026-01-01'),
            'image'       => $meta['image'] ?? null,
            'image_alt'   => $meta['image_alt'] ?? '',
            'html'        => $html,
            'minutes'     => max(1, (int) round($words / 200)),
            'url'         => route('articles.show', ['kind' => $kind, 'slug' => $slug]),
        ];
    }
}
