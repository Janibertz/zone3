<?php

namespace App\Services\AI;

/**
 * Was jeder Fachdienst an den Transport durchreicht.
 *
 * Coach-Persoenlichkeit, Nutzer und Tageslimit gehoeren zum Zugang, nicht
 * zur Fachlichkeit — die Aufrufstellen sollen davon aber nichts merken und
 * weiter `->withCoach(...)->forUser(...)` schreiben koennen.
 */
trait TalksToOpenAI
{
    public function __construct(protected readonly OpenAIClient $ai) {}

    public function withCoach(?string $personalityPrompt): static
    {
        $this->ai->withCoach($personalityPrompt);

        return $this;
    }

    public function forUser(?int $userId): static
    {
        $this->ai->forUser($userId);

        return $this;
    }

    /** Tageslimit des Nutzers erreicht? */
    public function isRateLimited(): bool
    {
        return $this->ai->isRateLimited();
    }
}
