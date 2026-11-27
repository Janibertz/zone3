<?php

namespace App\Console\Commands;

use App\Services\Instagram\InstagramClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
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
            if ($days === 0 && $client->usesFacebookToken()) {
                Log::warning('Instagram: Facebook-Token (EAA…) wird nicht verlängert');

                return self::SUCCESS;
            }
            $this->info("Instagram-Token verlängert, gilt {$days} Tage.");
        } catch (Throwable $e) {
            report($e);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
