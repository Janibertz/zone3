<?php

namespace App\Services\Instagram;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Die geplanten Beiträge: je Beitrag eine JSON-Datei in
 * resources/content/instagram/<slug>.json, sie kommen per Commit wie der Blog.
 *
 *   {
 *     "at": "2026-11-29 12:00",            Berliner Zeit
 *     "type": "trial_reel",                image | carousel | reel | trial_reel | story
 *     "media": ["https://zone3.run/social/…/trial-a.mp4"],
 *     "cover": "https://zone3.run/social/…/cover-a.jpg",   nur Reels, optional
 *     "caption": "…"                       nicht bei Stories
 *   }
 *
 * Medien müssen öffentlich erreichbar sein, Instagram holt sie selbst.
 * Sticker (Link, Umfrage, Fragen) kann die API nicht setzen. Solche Stories
 * sind "manual_story" mit "sticker": {"type": "link|poll|question|quiz|slider",
 * "text": "…", "url": "…", "options": ["…"]} und optional "note". Zur Uhrzeit
 * bekommen die Admins eine Nachricht mit Link auf /admin/instagram/<slug>,
 * dort liegen Bild/Video und alles, was in den Sticker gehört.
 */
class InstagramSchedule
{
    public const TYPES = ['image', 'carousel', 'reel', 'trial_reel', 'story', 'manual_story'];

    /** Diese Typen veröffentlicht niemand automatisch: zur Zeit kommt eine Nachricht an die Admins. */
    public const MANUAL = ['manual_story'];

    public const STICKERS = ['link' => 'Link', 'poll' => 'Umfrage', 'question' => 'Fragen', 'quiz' => 'Quiz', 'slider' => 'Regler'];

    public function __construct(private ?string $dir = null)
    {
        $this->dir ??= resource_path('content/instagram');
    }

    /** @return array<int, array{slug:string, at:Carbon, type:string, media:array, cover:?string, caption:string, sticker:?array, note:?string}> */
    public function all(): array
    {
        $posts = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $posts[] = $this->parse(basename($file, '.json'), json_decode(file_get_contents($file), true));
        }

        usort($posts, fn ($a, $b) => $a['at'] <=> $b['at']);

        return $posts;
    }

    public function parse(string $slug, mixed $data): array
    {
        if (! is_array($data)) {
            throw new InvalidArgumentException("Instagram-Beitrag {$slug}: kein gültiges JSON");
        }

        $type  = $data['type'] ?? null;
        $media = array_values(array_filter((array) ($data['media'] ?? [])));

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Instagram-Beitrag {$slug}: unbekannter Typ");
        }
        if ($media === [] || ($type === 'carousel' ? count($media) < 2 || count($media) > 10 : count($media) !== 1)) {
            throw new InvalidArgumentException("Instagram-Beitrag {$slug}: falsche Zahl an Medien");
        }

        return [
            'slug'    => $slug,
            'at'      => Carbon::parse($data['at'] ?? throw new InvalidArgumentException("Instagram-Beitrag {$slug}: ohne at"), 'Europe/Berlin'),
            'type'    => $type,
            'media'   => $media,
            'cover'   => $data['cover'] ?? null,
            'caption' => (string) ($data['caption'] ?? ''),
            'sticker' => $this->sticker($slug, $data['sticker'] ?? null),
            'note'    => $data['note'] ?? null,
        ];
    }

    public function find(string $slug): ?array
    {
        foreach ($this->all() as $post) {
            if ($post['slug'] === $slug) {
                return $post;
            }
        }

        return null;
    }

    private function sticker(string $slug, mixed $sticker): ?array
    {
        if ($sticker === null) {
            return null;
        }
        if (! is_array($sticker) || ! isset(self::STICKERS[$sticker['type'] ?? ''])) {
            throw new InvalidArgumentException("Instagram-Beitrag {$slug}: unbekannter Sticker");
        }

        return [
            'type'    => $sticker['type'],
            'label'   => self::STICKERS[$sticker['type']],
            'text'    => $sticker['text'] ?? null,
            'url'     => $sticker['url'] ?? null,
            'options' => array_values((array) ($sticker['options'] ?? [])),
        ];
    }

    public static function isVideo(string $url): bool
    {
        return (bool) preg_match('/\.(mp4|mov)(\?.*)?$/i', $url);
    }
}
