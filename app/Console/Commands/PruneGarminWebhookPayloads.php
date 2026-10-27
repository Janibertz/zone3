<?php

namespace App\Console\Commands;

use App\Models\GarminWebhookEvent;
use Illuminate\Console\Command;

/**
 * Rohdaten der Garmin-Pushs nach 14 Tagen leeren.
 *
 * Sie sind zum Nachsehen da, wenn ein Wert nicht ankommt — kein Archiv.
 * Gesundheitsdaten bleiben nicht laenger liegen als noetig; die Zeile selbst
 * (wann, wer, was daraus wurde) bleibt stehen.
 */
class PruneGarminWebhookPayloads extends Command
{
    public const KEEP_DAYS = 14;

    protected $signature   = 'garmin:prune-webhook-payloads';
    protected $description = 'Leert die Rohdaten alter Garmin-Pushs.';

    public function handle(): int
    {
        $cleared = GarminWebhookEvent::whereNotNull('payload')
            ->where('created_at', '<', now()->subDays(self::KEEP_DAYS))
            ->update(['payload' => null]);

        $this->info("{$cleared} Rohdaten geleert.");

        return self::SUCCESS;
    }
}
