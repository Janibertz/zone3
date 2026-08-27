<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Die Indizes fuer die haeufigen Abfragen sind da.
 *
 * Ein Index, den eine Migration anlegt und den niemand prueft, verschwindet
 * beim naechsten Umbau der Tabelle still — und faellt erst auf, wenn eine
 * Seite langsam wird.
 */
class HotPathIndexTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> Alle Spaltenkombinationen der Tabelle. */
    private function indexes(string $table): array
    {
        return collect(Schema::getIndexes($table))
            ->map(fn ($i) => implode(',', $i['columns']))
            ->values()
            ->all();
    }

    public function test_sessions_are_indexed_by_athlete_and_date(): void
    {
        $this->assertContains('user_id,planned_date', $this->indexes('training_sessions'));
    }

    public function test_sessions_are_indexed_for_the_review_comparison(): void
    {
        $this->assertContains('user_id,type,status', $this->indexes('training_sessions'));
    }

    public function test_the_chat_history_is_indexed(): void
    {
        $this->assertContains('user_id,created_at', $this->indexes('coach_messages'));
    }

    public function test_wellbeing_is_indexed_by_athlete_and_date(): void
    {
        $this->assertContains('user_id,date', $this->indexes('wellbeing_entries'));
    }

    /**
     * Was ein aelterer Systemreview als fehlend gemeldet hatte, war laengst
     * da. strava_id traegt eine Unique-Bedingung, die in jeder Datenbank ein
     * Index ist.
     *
     * activity_id laesst sich hier nicht pruefen: InnoDB legt fuer einen
     * Fremdschluessel automatisch einen Index an, SQLite — die Testverbindung
     * — tut das nicht. Die Zusage gilt also in Produktion, aber nicht unter
     * dieser Suite, und ein Test, der etwas anderes behauptet, waere
     * schlimmer als keiner.
     */
    public function test_strava_id_was_already_indexed(): void
    {
        $this->assertContains('strava_id', $this->indexes('activities'));
    }
}
