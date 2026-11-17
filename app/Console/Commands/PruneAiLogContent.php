<?php

namespace App\Console\Commands;

use App\Models\AiLog;
use Illuminate\Console\Command;

/**
 * Prompts und Antworten in ai_logs nach 30 Tagen leeren.
 *
 * Die Texte enthalten Gesundheitswerte (HRV, Ruhepuls, Schlaf, Tagesform) und
 * sind zum Nachsehen da, wenn das Modell etwas Seltsames schreibt — kein Archiv.
 * Die Zeile selbst bleibt: Token, Kosten, Modell, Dauer und Status braucht das
 * Tagesbudget und die Kostenübersicht im Admin. Fehlermeldungen bleiben auch,
 * sie tragen keine Athletendaten.
 */
class PruneAiLogContent extends Command
{
    public const KEEP_DAYS = 30;

    protected $signature   = 'ai:prune-logs';
    protected $description = 'Leert Prompts und Antworten alter KI-Aufrufe (Kosten und Token bleiben).';

    public function handle(): int
    {
        $cleared = AiLog::where('created_at', '<', now()->subDays(self::KEEP_DAYS))
            ->where(fn ($q) => $q->whereNotNull('full_prompt')
                ->orWhereNotNull('full_response')
                ->orWhereNotNull('prompt_preview')
                ->orWhereNotNull('response_preview'))
            ->update([
                'full_prompt'      => null,
                'full_response'    => null,
                'prompt_preview'   => null,
                'response_preview' => null,
            ]);

        $this->info("{$cleared} KI-Protokolle geleert.");

        return self::SUCCESS;
    }
}
