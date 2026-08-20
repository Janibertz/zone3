<?php

namespace App\Observers;

use App\Models\CoachMessage;
use App\Services\WebPushService;
use Illuminate\Support\Facades\Log;

/**
 * Eine Benachrichtigung fuer jede Nachricht des Coaches.
 *
 * Vorher verschickte nur das Session-Review einen Push, und zwar aus dem
 * Job heraus. Alles andere, was der Coach von sich aus schreibt, kam
 * lautlos an — man sah es erst, wenn man den Chat zufaellig oeffnete.
 *
 * Hier statt an den einzelnen Aufrufstellen, damit keine kuenftige Quelle
 * es vergisst.
 */
class CoachMessageObserver
{
    public function created(CoachMessage $message): void
    {
        // Nachrichten des Athleten an sich selbst zu melden waere unsinnig.
        if ($message->role !== 'assistant') {
            return;
        }

        $user = $message->user;
        if (! $user || ! $user->push_notifications_enabled) {
            return;
        }

        try {
            app(WebPushService::class)->sendToUser(
                $user,
                ($user->coach?->name ?? 'Dein Coach') . ' 💬',
                mb_strimwidth(strip_tags($message->content), 0, 140, '…'),
                '/dashboard?coach=1',
            );
        } catch (\Throwable $e) {
            // Eine fehlgeschlagene Benachrichtigung darf die Nachricht selbst
            // nicht verhindern — sie steht bereits im Verlauf.
            Log::warning('Coach-Push fehlgeschlagen', [
                'message_id' => $message->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
