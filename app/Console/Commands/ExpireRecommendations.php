<?php

namespace App\Console\Commands;

use App\Models\SessionRecommendation;
use Illuminate\Console\Command;

/** Offene Vorschläge zu vergangenen oder erledigten Einheiten verfallen lassen. */
class ExpireRecommendations extends Command
{
    protected $signature = 'recommendations:expire';

    protected $description = 'Offene Coach-Vorschläge zu vergangenen Einheiten verfallen lassen';

    public function handle(): int
    {
        $this->info(SessionRecommendation::expireStale() . ' Vorschläge verfallen.');

        return self::SUCCESS;
    }
}
