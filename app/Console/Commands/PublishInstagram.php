<?php

namespace App\Console\Commands;

use App\Services\Instagram\InstagramPublisher;
use Illuminate\Console\Command;

/** Jede Minute: fällige Beiträge aus resources/content/instagram veröffentlichen. */
class PublishInstagram extends Command
{
    protected $signature = 'instagram:publish';

    protected $description = 'Veröffentlicht fällige Instagram-Beiträge';

    public function handle(InstagramPublisher $publisher): int
    {
        foreach ($publisher->run() as $slug => $what) {
            $this->line("{$slug}: {$what}");
        }

        return self::SUCCESS;
    }
}
