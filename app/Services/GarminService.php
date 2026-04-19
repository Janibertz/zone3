<?php

namespace App\Services;

use App\Models\GarminAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GarminService
{
    private string $ssoBase     = 'https://sso.garmin.com/sso';
    private string $connectBase = 'https://connect.garmin.com';

    // ── Authentication ────────────────────────────────────────────────────────

    public function login(string $email, string $password): array
    {
        $params = http_build_query([
            'service'                       => $this->connectBase . '/modern/',
            'gauthHost'                     => $this->ssoBase,
            'locale'                        => 'en_US',
            'id'                            => 'gauth-widget',
            'cssUrl'                        => 'https://static.garmincdn.com/com.garmin.connect/ui/css/gauth-custom-v1.2-min.css',
            'clientId'                      => 'GarminConnect',
            'rememberMeShown'               => 'true',
            'rememberMeChecked'             => 'false',
            'createAccountShown'            => 'true',
            'openCreateAccount'             => 'false',
            'displayNameShown'              => 'false',
            'consumeServiceTicket'          => 'false',
            'initialFocus'                  => 'true',
            'embedWidget'                   => 'false',
            'generateExtraServiceTicket'    => 'true',
            'generateTwoExtraServiceTickets'=> 'false',
            'generateNoServiceTicket'       => 'false',
        ]);

        $signinUrl = $this->ssoBase . '/signin?' . $params;

        // Step 1: Get signin page + CSRF token
        $step1 = Http::withHeaders($this->baseHeaders())->get($signinUrl);

        if ($step1->failed()) {
            throw new \RuntimeException('Garmin SSO nicht erreichbar.');
        }

        preg_match('/name="_csrf"\s+value="([^"]+)"/', $step1->body(), $csrfMatch);
        $csrf = $csrfMatch[1] ?? null;

        if (! $csrf) {
            throw new \RuntimeException('Garmin CSRF-Token nicht gefunden.');
        }

        $cookieHeader = $this->extractCookieHeader($step1->headers());

        // Step 2: POST credentials
        $step2 = Http::withHeaders(array_merge($this->baseHeaders(), [
            'Referer' => $signinUrl,
            'Cookie'  => $cookieHeader,
        ]))->withOptions(['allow_redirects' => false])
          ->asForm()
          ->post($this->ssoBase . '/signin?' . $params, [
            'username'      => $email,
            'password'      => $password,
            '_csrf'         => $csrf,
            'embed'         => 'false',
            'rememberme'    => 'on',
          ]);

        // Get ticket from redirect location
        $location = $step2->header('Location') ?? '';
        preg_match('/ticket=([^&]+)/', $location, $ticketMatch);
        $ticket = $ticketMatch[1] ?? null;

        if (! $ticket) {
            throw new \RuntimeException('Garmin Login fehlgeschlagen. Bitte E-Mail und Passwort prüfen.');
        }

        $cookieHeader .= '; ' . $this->extractCookieHeader($step2->headers());

        // Step 3: Exchange ticket at Garmin Connect
        $step3 = Http::withHeaders(array_merge($this->baseHeaders(), [
            'Cookie' => $cookieHeader,
        ]))->withOptions(['allow_redirects' => true])
          ->get($this->connectBase . '/modern/?ticket=' . $ticket);

        $finalCookies = $this->parseCookies(
            $step1->headers(),
            $step2->headers(),
            $step3->headers(),
        );

        if (empty($finalCookies)) {
            throw new \RuntimeException('Garmin Session-Cookies konnten nicht gespeichert werden.');
        }

        return $finalCookies;
    }

    public function ensureFreshSession(GarminAccount $account): void
    {
        if ($account->hasFreshCookies()) return;

        $cookies = $this->login($account->email, $account->getDecryptedPassword());
        $account->update([
            'cookies'           => $cookies,
            'cookies_expire_at' => now()->addHours(23),
        ]);
    }

    // ── Workout upload ────────────────────────────────────────────────────────

    public function uploadWorkout(GarminAccount $account, array $session): ?int
    {
        $this->ensureFreshSession($account);

        $payload = $this->buildWorkoutPayload($session);

        $response = Http::withHeaders(array_merge($this->baseHeaders(), [
            'Cookie'       => $this->cookieString($account->cookies),
            'NK'           => 'NT',
            'Content-Type' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]))->post($this->connectBase . '/proxy/workout-service/workout', $payload);

        if ($response->failed()) {
            Log::error('Garmin workout upload failed', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        }

        return $response->json('workoutId');
    }

    public function scheduleWorkout(GarminAccount $account, int $workoutId, string $date): bool
    {
        $this->ensureFreshSession($account);

        $response = Http::withHeaders(array_merge($this->baseHeaders(), [
            'Cookie'       => $this->cookieString($account->cookies),
            'NK'           => 'NT',
            'Content-Type' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]))->post($this->connectBase . "/proxy/workout-service/schedule/{$workoutId}", [
            'date' => $date,
        ]);

        return $response->successful();
    }

    public function pushSession(GarminAccount $account, array $session): bool
    {
        if (($session['type'] ?? '') === 'rest') return true;

        $workoutId = $this->uploadWorkout($account, $session);
        if (! $workoutId) return false;

        return $this->scheduleWorkout($account, $workoutId, $session['planned_date']);
    }

    // ── Workout payload builder ───────────────────────────────────────────────

    private function buildWorkoutPayload(array $session): array
    {
        $steps  = $this->buildSteps($session);
        $distKm = $session['distance_km'] ?? null;

        return [
            'workoutName'           => $session['title'] ?? 'Training',
            'description'           => $session['description'] ?? '',
            'sport'                 => ['sportType' => ['sportTypeId' => 1, 'sportTypeKey' => 'running']],
            'estimatedDistanceUnit' => ['unitKey' => 'kilometer'],
            'estimatedDurationInSecs' => isset($session['duration_min']) ? (int) $session['duration_min'] * 60 : null,
            'workoutSegments'       => [[
                'segmentOrder' => 1,
                'sportType'    => ['sportTypeId' => 1, 'sportTypeKey' => 'running'],
                'workoutSteps' => $steps,
            ]],
        ];
    }

    private function buildSteps(array $session): array
    {
        $distKm     = $session['distance_km'] ?? 5;
        $paceTarget = $session['pace_target'] ?? null;
        $type       = $session['type'] ?? 'easy_run';

        $paceSecPerKm = $this->paceToSeconds($paceTarget);
        $easyPaceSec  = $paceSecPerKm ? $paceSecPerKm + 60 : null;

        // Warmup / cooldown fractions by type
        $configs = [
            'easy_run'  => ['wF' => 0.10, 'wMax' => 1.0, 'cF' => 0.10, 'cMax' => 1.0],
            'tempo_run' => ['wF' => 0.25, 'wMax' => 2.0, 'cF' => 0.12, 'cMax' => 1.0],
            'interval'  => ['wF' => 0.20, 'wMax' => 2.0, 'cF' => 0.10, 'cMax' => 1.0],
            'long_run'  => ['wF' => 0.05, 'wMax' => 1.0, 'cF' => 0.05, 'cMax' => 1.0],
            'race_prep' => ['wF' => 0.30, 'wMax' => 2.0, 'cF' => 0.15, 'cMax' => 1.0],
        ];
        $cfg = $configs[$type] ?? $configs['easy_run'];

        $warmupKm   = round(min($cfg['wMax'], $distKm * $cfg['wF']), 2);
        $cooldownKm = round(min($cfg['cMax'], $distKm * $cfg['cF']), 2);
        $mainKm     = round(max(0, $distKm - $warmupKm - $cooldownKm), 2);

        $steps = [];
        $order = 1;

        // Warmup
        if ($warmupKm > 0) {
            $steps[] = $this->distanceStep($order++, 'warmup', $warmupKm, $easyPaceSec);
        }

        // Main
        if ($mainKm > 0) {
            $steps[] = $this->distanceStep($order++, 'interval', $mainKm, $paceSecPerKm);
        }

        // Cooldown
        if ($cooldownKm > 0) {
            $steps[] = $this->distanceStep($order++, 'cooldown', $cooldownKm, $easyPaceSec);
        }

        return $steps;
    }

    private function distanceStep(int $order, string $typeKey, float $distKm, ?int $paceSecPerKm): array
    {
        $typeMap = [
            'warmup'   => ['id' => 3, 'key' => 'warmup'],
            'interval' => ['id' => 1, 'key' => 'interval'],
            'cooldown' => ['id' => 2, 'key' => 'cooldown'],
        ];
        $t = $typeMap[$typeKey] ?? $typeMap['interval'];

        $step = [
            'stepOrder'    => $order,
            'stepType'     => ['stepTypeId' => $t['id'], 'stepTypeKey' => $t['key']],
            'endCondition' => ['conditionTypeId' => 3, 'conditionTypeKey' => 'distance'],
            'endConditionValue' => (int) round($distKm * 1000), // metres
        ];

        if ($paceSecPerKm && $paceSecPerKm > 0) {
            // Garmin uses speed (m/s * 1000) in pace targets — but pace zone uses sec/km
            $step['targetType']     = ['workoutTargetTypeId' => 6, 'workoutTargetTypeKey' => 'pace.zone'];
            $step['targetValueOne'] = (int) round($paceSecPerKm * 0.9);  // faster bound
            $step['targetValueTwo'] = (int) round($paceSecPerKm * 1.1);  // slower bound
        } else {
            $step['targetType'] = ['workoutTargetTypeId' => 1, 'workoutTargetTypeKey' => 'no.target'];
        }

        return $step;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function paceToSeconds(?string $pace): ?int
    {
        if (! $pace || ! preg_match('/^(\d+):(\d{2})/', $pace, $m)) return null;
        return (int) $m[1] * 60 + (int) $m[2];
    }

    private function baseHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.5',
        ];
    }

    private function extractCookieHeader(array $headers): string
    {
        $setCookie = $headers['Set-Cookie'] ?? $headers['set-cookie'] ?? [];
        if (is_string($setCookie)) $setCookie = [$setCookie];
        $parts = [];
        foreach ($setCookie as $line) {
            $segment = explode(';', $line)[0];
            if (trim($segment)) $parts[] = trim($segment);
        }
        return implode('; ', $parts);
    }

    private function parseCookies(array ...$headerSets): array
    {
        $cookies = [];
        foreach ($headerSets as $headers) {
            $setCookie = $headers['Set-Cookie'] ?? $headers['set-cookie'] ?? [];
            if (is_string($setCookie)) $setCookie = [$setCookie];
            foreach ($setCookie as $line) {
                $segment = explode(';', $line)[0];
                if (strpos($segment, '=') !== false) {
                    [$name, $value] = explode('=', $segment, 2);
                    $cookies[trim($name)] = trim($value);
                }
            }
        }
        return $cookies;
    }

    private function cookieString(array $cookies): string
    {
        return implode('; ', array_map(
            fn ($k, $v) => "{$k}={$v}",
            array_keys($cookies),
            array_values($cookies)
        ));
    }
}
