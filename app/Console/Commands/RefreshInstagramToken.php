<?php

namespace App\Console\Commands;

use App\Services\Instagram\InstagramClient;
use Illuminate\Console\Command;
use Throwable;

/** Wöchentlich: verlängert den Instagram-Token (gilt sonst 60 Tage). */
class RefreshInstagramToken extends Command
{
    protected $signature = 'instagram:refresh-token';

    protected $description = 'Verlängert den Instagram-Zugriffstoken';

    public function handle(InstagramClient $client): int
    {
        if (! $client->configured()) {
            return self::SUCCESS;
        }

        try {
            $days = intdiv($client->refreshToken(), 86400);
            $this->info("Instagram-Token verlängert, gilt {$days} Tage.");
        } catch (Throwable $e) {
            report($e);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
