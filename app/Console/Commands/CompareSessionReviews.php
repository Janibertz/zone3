<?php

namespace App\Console\Commands;

use App\Jobs\GenerateSessionReviewJob;
use App\Models\TrainingSession;
use App\Services\AI\SessionContentService;
use App\Services\Review\SessionHighlights;
use App\Services\TrainingLoadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Alte und neue Coach-Nachricht nebeneinander, ohne etwas zu speichern.
 *
 * Gebaut für den Umbau vom 12.11.2026: bevor der neue Prompt live
 * Nachrichten schreibt, soll Jan an echten Einheiten sehen, wie er klingt.
 * Kostet je Einheit ein bis zwei Aufrufe beim mittleren Modell (Flex),
 * schreibt nichts in die Einheit und legt keine Chat-Nachricht an.
 *
 * Kontext wie Ziel, nächste Einheit und letzte Nachrichten ist der von
 * heute, nicht der vom Tag der Einheit.
 */
class CompareSessionReviews extends Command
{
    protected $signature = 'review:compare
        {user          : ID des Athleten}
        {--limit=10    : Wie viele der letzten Einheiten mit Review}
        {--yes         : Ohne Rückfrage ausführen}';

    protected $description = 'Legt alte und neue Coach-Nachricht nach einer Einheit nebeneinander (speichert nichts).';

    public function handle(SessionContentService $sessions, TrainingLoadService $load): int
    {
        $rows = TrainingSession::with(['user.coach', 'activity'])
            ->where('user_id', (int) $this->argument('user'))
            ->whereNotNull('coach_review')
            ->orderByDesc('planned_date')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($rows->isEmpty()) {
            $this->line('Keine Einheiten mit Review gefunden.');

            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm("{$rows->count()} Einheiten, je ein bis zwei KI-Aufrufe. Weiter?")) {
            return self::SUCCESS;
        }

        $out = ['# Coach-Nachricht: alt und neu', '', 'Erstellt ' . now()->format('d.m.Y H:i') . ', nichts gespeichert.', ''];

        foreach ($rows as $s) {
            $job    = new GenerateSessionReviewJob($s->id);
            $minutes = $s->activity?->moving_time ? (int) round($s->activity->moving_time / 60) : null;
            $silent = SessionHighlights::silent($s->sport_type ?? $s->activity?->type, $minutes);
            $new    = $silent ? null : $job->draft($s, $sessions, $load);

            $out[] = "## {$s->planned_date->format('d.m.Y')} · {$s->title}";
            $out[] = '';
            $out[] = '**Alt:** ' . preg_replace('/\s+/', ' ', (string) $s->coach_review);
            $out[] = '';
            $out[] = match (true) {
                $silent       => '**Neu:** keine Nachricht (Alltagsbewegung)',
                $new === null => '**Neu:** keine Antwort vom Modell',
                default       => "**Neu** ({$new['highlight']}): {$new['review']}"
                    . ($new['question'] ? "\n\nFrage: {$new['question']} [" . implode(' · ', $new['options']) . ']' : ''),
            };
            $out[] = '';

            $this->line("✓ #{$s->id}");
        }

        $path = 'review-compare/' . now()->format('Y-m-d-His') . '.md';
        Storage::disk('local')->put($path, implode("\n", $out));

        $this->newLine();
        $this->line(implode("\n", $out));
        $this->info('Gespeichert unter storage/app/private/' . $path);

        return self::SUCCESS;
    }
}
