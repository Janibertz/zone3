<?php

namespace App\Http\Controllers;

use App\Jobs\RegeneratePlanJob;
use App\Models\Event;
use App\Models\User;
use App\Services\GoalCheckService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Die wöchentliche Zielprüfung: Passt die Zielzeit noch zum Athleten?
 *
 * Der Plan steht auf der Annahme, dass das Ziel erreichbar ist. Bisher hat
 * diese Annahme niemand nachgerechnet — sie wurde beim Anlegen des Events
 * einmal eingetragen und dann monatelang durchgezogen.
 *
 * Gefragt wird nur, wenn es etwas zu fragen gibt (siehe
 * {@see GoalCheckService}), und höchstens einmal pro Woche. Wer sich
 * entschieden hat, bekommt dieselbe Frage nicht am nächsten Sonntag
 * wieder — sonst klickt man sie weg wie jede andere Benachrichtigung.
 */
class GoalCheckController extends Controller
{
    /** Das Event, um dessen Ziel es geht: das nächste A- oder B-Rennen. */
    public static function eventFor(User $user): ?Event
    {
        return Event::where('user_id', $user->id)
            ->whereDate('event_date', '>=', now()->toDateString())
            ->whereIn('priority', ['A', 'B'])
            ->orderBy('event_date')
            ->first();
    }

    /**
     * Steht die Frage diese Woche noch aus?
     *
     * Zwei Sperren: die laufende Woche (einmal fragen reicht) und eine
     * ausdrückliche Bestätigung, die vier Wochen hält. Wer bei seinem Ziel
     * bleibt, hat das entschieden — erst wenn sich die Lage deutlich ändert,
     * ist die Frage wieder neu, und dafür sorgt die erneute Prüfung des
     * Urteils.
     */
    public static function isDue(?Event $event): bool
    {
        if (! $event) {
            return false;
        }

        if ($event->goal_check_week === self::weekKey()) {
            return false;
        }

        return $event->goal_confirmed_at === null
            || $event->goal_confirmed_at->lt(now()->subWeeks(4));
    }

    public static function weekKey(): string
    {
        return CarbonImmutable::today()->format('o-\WW');
    }

    /** „Ich bleibe bei meinem Ziel." */
    public function confirm(Request $request): JsonResponse
    {
        $event = self::eventFor($request->user());

        if (! $event) {
            return response()->json(['message' => 'Kein Zielrennen gefunden.'], 422);
        }

        $event->update([
            'goal_check_week'   => self::weekKey(),
            'goal_confirmed_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Neues Ziel setzen und den Plan darauf rechnen lassen.
     *
     * Die Neuberechnung laeuft als Job — ein OpenAI-Aufruf im Request
     * blockiert den einzigen Webprozess und damit die ganze Seite.
     */
    public function adjust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hours'   => 'required|integer|min:0|max:23',
            'minutes' => 'required|integer|min:0|max:59',
        ]);

        $event = self::eventFor($request->user());

        if (! $event) {
            return response()->json(['message' => 'Kein Zielrennen gefunden.'], 422);
        }

        if ($data['hours'] === 0 && $data['minutes'] === 0) {
            return response()->json(['message' => 'Eine Zielzeit von 0:00 ergibt keinen Plan.'], 422);
        }

        $event->update([
            'target_time_hours'   => $data['hours'],
            'target_time_minutes' => $data['minutes'],
            'goal_check_week'     => self::weekKey(),
            // Ein frisch gesetztes Ziel ist eine Entscheidung wie jede
            // andere — sonst fragt die Prüfung nächste Woche direkt nach.
            'goal_confirmed_at'   => now(),
        ]);

        RegeneratePlanJob::dispatch($request->user()->id, userTriggered: true);

        return response()->json([
            'success'     => true,
            'target'      => $event->fresh()->target_time_formatted,
            'regenerating'=> true,
        ]);
    }

    /**
     * „Erklär mir das." Die Frage ist damit für diese Woche gestellt und
     * beantwortet — weiter geht es im Chat, nicht auf der Karte.
     */
    public function discuss(Request $request): JsonResponse
    {
        $event = self::eventFor($request->user());
        $event?->update(['goal_check_week' => self::weekKey()]);

        return response()->json(['success' => true]);
    }

    /** Der aktuelle Befund — für Dashboard und Push. */
    public static function current(User $user, GoalCheckService $service): ?array
    {
        $event = self::eventFor($user);

        if (! self::isDue($event)) {
            return null;
        }

        $check = $service->forEvent($user, $event);

        return $check ? $check + ['event_id' => $event->id, 'event_name' => $event->name] : null;
    }
}
