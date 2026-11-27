<?php

namespace App\Services\Instagram;

use App\Models\InstagramPublication;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Veröffentlicht fällige Beiträge in zwei Schritten über mehrere Läufe:
 * Container anlegen (waiting), und sobald Instagram ihn fertig verarbeitet hat,
 * veröffentlichen. Kein Warten im Prozess — ein Reel braucht dort Minuten.
 *
 * Ein Fehler steht an der Zeile und geht als Log::error raus (Sofort-Push an
 * die Admins über InstantAlert). Wiederholt wird nichts von selbst: ein halb
 * veröffentlichtes Karussell zweimal zu posten wäre schlimmer als keins.
 */
class InstagramPublisher
{
    /** Später als das wird ein Beitrag nicht mehr nachgeholt. */
    public const CATCH_UP_HOURS = 6;

    /** So lange darf Instagram an einem Container rechnen. */
    public const PROCESSING_MINUTES = 30;

    public function __construct(
        private InstagramClient $client,
        private InstagramSchedule $schedule,
    ) {}

    /** @return array<string, string> slug => was passiert ist */
    public function run(): array
    {
        $done  = [];
        $known = InstagramPublication::pluck('status', 'slug');

        foreach ($this->schedule->all() as $post) {
            if ($post['at']->isFuture()) {
                continue;
            }

            $status = $known[$post['slug']] ?? null;

            if ($status === null) {
                $done[$post['slug']] = $post['at']->lt(now()->subHours(self::CATCH_UP_HOURS))
                    ? $this->miss($post)
                    : $this->start($post);
            } elseif ($status === InstagramPublication::WAITING) {
                $done[$post['slug']] = $this->advance(InstagramPublication::where('slug', $post['slug'])->first());
            }
        }

        return $done;
    }

    private function start(array $post): string
    {
        $row = InstagramPublication::create([
            'slug'         => $post['slug'],
            'type'         => $post['type'],
            'scheduled_at' => $post['at'],
            'status'       => InstagramPublication::WAITING,
        ]);

        try {
            $row->update(['container_id' => $this->createContainer($post)]);
        } catch (Throwable $e) {
            return $this->fail($row, $e->getMessage());
        }

        return $this->advance($row);
    }

    private function advance(InstagramPublication $row): string
    {
        try {
            $state = $this->client->containerStatus($row->container_id);
            $code  = $state['status_code'] ?? 'IN_PROGRESS';

            if ($code === 'IN_PROGRESS') {
                if ($row->created_at->lt(now()->subMinutes(self::PROCESSING_MINUTES))) {
                    return $this->fail($row, 'Instagram hat den Beitrag nach ' . self::PROCESSING_MINUTES . ' Minuten noch nicht verarbeitet.');
                }

                return 'wartet';
            }

            if ($code !== 'FINISHED') {
                return $this->fail($row, "Instagram meldet {$code}: " . ($state['status'] ?? ''));
            }

            $mediaId = $this->client->publish($row->container_id);
            $row->update([
                'status'       => InstagramPublication::PUBLISHED,
                'media_id'     => $mediaId,
                'permalink'    => $this->client->permalink($mediaId),
                'published_at' => now(),
                'error'        => null,
            ]);
            Log::info('Instagram: Beitrag veröffentlicht', ['slug' => $row->slug, 'permalink' => $row->permalink]);

            return 'veröffentlicht';
        } catch (Throwable $e) {
            return $this->fail($row, $e->getMessage());
        }
    }

    private function createContainer(array $post): string
    {
        $url = $post['media'][0];

        return match ($post['type']) {
            'image' => $this->client->createContainer(['image_url' => $url, 'caption' => $post['caption']]),
            'story' => $this->client->createContainer(['media_type' => 'STORIES', InstagramSchedule::isVideo($url) ? 'video_url' : 'image_url' => $url]),
            'reel', 'trial_reel' => $this->client->createContainer(array_filter([
                'media_type'    => 'REELS',
                'video_url'     => $url,
                'caption'       => $post['caption'],
                'cover_url'     => $post['cover'],
                'share_to_feed' => $post['type'] === 'reel' ? 'true' : null,
                'trial_params'  => $post['type'] === 'trial_reel' ? json_encode(['graduation_strategy' => 'MANUAL']) : null,
            ])),
            'carousel' => $this->client->createContainer([
                'media_type' => 'CAROUSEL',
                'caption'    => $post['caption'],
                'children'   => implode(',', array_map(fn ($item) => $this->client->createContainer(
                    InstagramSchedule::isVideo($item)
                        ? ['media_type' => 'VIDEO', 'video_url' => $item, 'is_carousel_item' => 'true']
                        : ['image_url' => $item, 'is_carousel_item' => 'true']
                ), $post['media'])),
            ]),
        };
    }

    private function miss(array $post): string
    {
        InstagramPublication::create([
            'slug'         => $post['slug'],
            'type'         => $post['type'],
            'scheduled_at' => $post['at'],
            'status'       => InstagramPublication::MISSED,
            'error'        => 'Mehr als ' . self::CATCH_UP_HOURS . ' Stunden über der Zeit, nicht mehr nachgeholt.',
        ]);

        return 'verpasst';
    }

    private function fail(InstagramPublication $row, string $message): string
    {
        $row->update(['status' => InstagramPublication::FAILED, 'error' => $message]);
        Log::error("Instagram: {$row->slug} nicht veröffentlicht: {$message}");

        return 'fehlgeschlagen';
    }
}
