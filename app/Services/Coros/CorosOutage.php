<?php

namespace App\Services\Coros;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Wann ein gescheiterter COROS-Abruf ein Fehler ist.
 *
 * MCP heißt Polling: der nächste Lauf kommt ohnehin in 15 bis 60 Minuten.
 * Ein einzelner Netzaussetzer (DNS-Timeout am 27.11.2026) oder ein 5xx von
 * COROS ist darum nur eine Warnung. Erst ab `BEFORE_REPORT` Läufen in Folge
 * wird er gemeldet (`report()` → Error im Log → Sofort-Push an die Admins).
 * Alles andere — abgelehnter Token, kaputte Antwort — wird sofort gemeldet.
 */
class CorosOutage
{
    public const BEFORE_REPORT = 3;

    public static function failed(Throwable $e, int $userId, string $scope): void
    {
        if (! CorosClient::transient($e)) {
            report($e);

            return;
        }

        $key = self::key($userId, $scope);
        Cache::add($key, 0, now()->addDay());
        $count = (int) Cache::increment($key);

        if ($count >= self::BEFORE_REPORT) {
            report($e);

            return;
        }

        Log::warning("COROS {$scope}: nicht erreichbar, nächster Lauf versucht es wieder", [
            'user_id'  => $userId,
            'in_folge' => $count,
            'error'    => Str::limit($e->getMessage(), 200),
        ]);
    }

    public static function succeeded(int $userId, string $scope): void
    {
        Cache::forget(self::key($userId, $scope));
    }

    private static function key(int $userId, string $scope): string
    {
        return "coros-outage:{$scope}:{$userId}";
    }
}
