<?php

namespace App\Services\Observability;

use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Support\Facades\Cache;

/**
 * Sagt sofort Bescheid, wenn eine Aufgabe scheitert oder ein Fehler im Log
 * landet — höchstens einmal pro Stunde.
 *
 * `perf:watch` prüft einmal am Tag, die Diagnose einmal die Woche. Ein
 * Absturz um 10 Uhr fiel so erst am nächsten Morgen auf (Jan, 14.11.2026:
 * „damit Fehler frühzeitig auffallen“). Bewusst kein Fremddienst wie
 * Sentry: dessen Meldungen tragen Daten aus dem Request mit, bei Zone3
 * auch Gesundheitswerte.
 *
 * Eine Nachricht pro Stunde für alles zusammen: ein Fehler in einer
 * Schleife soll das Telefon nicht hundertmal wecken. Was danach kommt,
 * steht im Log.
 */
class InstantAlert
{
    public const SLOT_KEY = 'observability:instant-alert';

    public const SLOT_SECONDS = 3600;

    private static bool $sending = false;

    public function __construct(private readonly WebPushService $push) {}

    public static function enabled(): bool
    {
        return (bool) config('observability.enabled', true)
            && (bool) config('observability.alerts.instant', true);
    }

    public function jobFailed(string $jobClass, \Throwable $e): void
    {
        $this->send('Aufgabe fehlgeschlagen: ' . class_basename($jobClass), self::firstLine($e->getMessage()));
    }

    public function errorLogged(string $level, string $message): void
    {
        $this->send('Fehler im Log (' . $level . ')', self::firstLine($message));
    }

    private function send(string $title, string $detail): void
    {
        // Ein Fehler beim Senden landet selbst im Log — ohne Sperre käme
        // er hier wieder an.
        if (self::$sending || ! self::enabled()) {
            return;
        }

        self::$sending = true;

        try {
            if (! Cache::add(self::SLOT_KEY, now()->toIso8601String(), self::SLOT_SECONDS)) {
                return;
            }

            $body = $title . ': ' . $detail . ' — weitere in der nächsten Stunde stehen im Log.';

            foreach (User::where('is_admin', true)->get() as $admin) {
                try {
                    $this->push->sendToUser($admin, 'Zone3 Fehler', $body, '/admin/system/logs');
                } catch (\Throwable) {
                    // Nicht melden: das wäre der nächste Fehler im Log.
                }
            }
        } catch (\Throwable) {
            // Der Cache oder die Datenbank selbst ist weg — dann hilft keine Nachricht.
        } finally {
            self::$sending = false;
        }
    }

    private static function firstLine(string $text): string
    {
        $line = trim(strtok($text, "\n") ?: $text);

        return mb_strlen($line) > 160 ? mb_substr($line, 0, 159) . '…' : $line;
    }
}
