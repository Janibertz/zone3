<?php

namespace App\Console\Commands;

use App\Jobs\GenerateSessionReviewJob;
use App\Models\TrainingSession;
use Illuminate\Console\Command;

/**
 * Ein Review neu schreiben lassen.
 *
 * Der Text einer Einheit entsteht einmal und bleibt dann stehen. Wurde er
 * auf falscher Grundlage geschrieben — etwa als eine Schwimmeinheit noch
 * als „Lockerer Lauf" in der Datenbank lag —, korrigiert ihn keine
 * Migration: sie repariert die Einheit, nicht den bereits verfassten Text.
 *
 * Dieser Befehl verwirft Review und Rückfrage und stösst die Erzeugung neu
 * an. Er kostet je Einheit einen Aufruf beim Sprachmodell und legt eine
 * neue Chat-Nachricht an — deshalb muss man ihn ausdrücklich aufrufen.
 */
class RewriteSessionReviews extends Command
{
    protected $signature = 'review:rewrite
        {--session=* : Einzelne Einheiten-IDs}
        {--user=     : Alle betroffenen Einheiten dieses Athleten}
        {--cross     : Nur Alternativtraining (Schwimmen, Rad, …)}
        {--days=30   : Wie weit zurueck}
        {--yes       : Ohne Rueckfrage ausfuehren}';

    protected $description = 'Verwirft vorhandene Coach-Reviews und laesst sie neu schreiben.';

    public function handle(): int
    {
        $query = TrainingSession::whereNotNull('reviewed_at')
            ->where('planned_date', '>=', now()->subDays((int) $this->option('days'))->toDateString());

        if ($ids = $this->option('session')) {
            $query->whereIn('id', $ids);
        }

        if ($userId = $this->option('user')) {
            $query->where('user_id', $userId);
        }

        if ($this->option('cross')) {
            $query->where('type', 'cross_training');
        }

        $sessions = $query->orderBy('planned_date')->get(['id', 'planned_date', 'type', 'sport_type', 'title']);

        if ($sessions->isEmpty()) {
            $this->line('Keine passenden Einheiten gefunden.');

            return self::SUCCESS;
        }

        foreach ($sessions as $session) {
            $this->line(sprintf(
                '↻ #%d  %s  %s%s',
                $session->id,
                $session->planned_date->format('d.m.Y'),
                $session->sport_type ? $session->sportLabel() . ' — ' : '',
                $session->title,
            ));
        }

        if (! $this->option('yes') && ! $this->confirm("{$sessions->count()} Review(s) neu schreiben?", true)) {
            return self::SUCCESS;
        }

        foreach ($sessions as $session) {
            // Der Job kehrt bei gesetztem reviewed_at sofort zurueck.
            TrainingSession::where('id', $session->id)->update([
                'coach_review'    => null,
                'review_question' => null,
                'review_options'  => null,
                'review_feedback' => null,
                'reviewed_at'     => null,
            ]);

            GenerateSessionReviewJob::dispatch($session->id);
        }

        $this->info("{$sessions->count()} Review(s) neu angestossen.");

        return self::SUCCESS;
    }
}
