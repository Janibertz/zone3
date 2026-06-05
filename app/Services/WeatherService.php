<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Current-weather lookup at the user's training location via Open-Meteo
 * (free, no API key). Used to give the AI coach weather context and to show
 * a small weather chip on the dashboard.
 *
 * Everything degrades gracefully to null: a missing location, an API timeout,
 * or a parse failure simply means "no weather" — never an error for the caller.
 */
class WeatherService
{
    private const FORECAST_URL  = 'https://api.open-meteo.com/v1/forecast';
    private const GEOCODE_URL   = 'https://geocoding-api.open-meteo.com/v1/search';
    private const WEATHER_TTL   = 7200;      // 2h
    private const GEOCODE_TTL   = 2592000;   // 30d
    private const HTTP_TIMEOUT  = 4;

    /**
     * Resolve the user's location and return today's weather, display- and
     * AI-ready, or null if unavailable.
     */
    public function forUser(User $user): ?array
    {
        $coords = $this->resolveCoords($user);
        if (! $coords) {
            return null;
        }

        [$lat, $lng] = $coords;
        $cacheKey = sprintf('weather:%.2f:%.2f:%s', $lat, $lng, now()->toDateString());

        return Cache::remember($cacheKey, self::WEATHER_TTL, fn () => $this->fetchWeather($lat, $lng));
    }

    /**
     * Training-location coordinates: most recent run with stored coordinates,
     * else the geocoded profile city, else null.
     *
     * @return array{0: float, 1: float}|null
     */
    public function resolveCoords(User $user): ?array
    {
        $activity = Activity::where('user_id', $user->id)
            ->whereNotNull('start_lat')
            ->whereNotNull('start_lng')
            ->orderByDesc('start_date')
            ->first(['start_lat', 'start_lng']);

        if ($activity) {
            return [(float) $activity->start_lat, (float) $activity->start_lng];
        }

        if (! empty($user->location)) {
            return $this->geocode($user->location);
        }

        return null;
    }

    /**
     * City name → [lat, lng] via Open-Meteo geocoding, cached.
     *
     * @return array{0: float, 1: float}|null
     */
    public function geocode(string $city): ?array
    {
        $city = trim($city);
        if ($city === '') {
            return null;
        }

        $cacheKey = 'geocode:' . md5(mb_strtolower($city));

        return Cache::remember($cacheKey, self::GEOCODE_TTL, function () use ($city) {
            try {
                $res = Http::timeout(self::HTTP_TIMEOUT)->get(self::GEOCODE_URL, [
                    'name'     => $city,
                    'count'    => 1,
                    'language' => 'de',
                ]);
                $hit = $res->json('results.0');
                if (! $hit || ! isset($hit['latitude'], $hit['longitude'])) {
                    return null;
                }
                return [(float) $hit['latitude'], (float) $hit['longitude']];
            } catch (\Throwable $e) {
                Log::warning('WeatherService geocode failed: ' . $e->getMessage());
                return null;
            }
        });
    }

    /** Fetch + normalize current weather. */
    private function fetchWeather(float $lat, float $lng): ?array
    {
        try {
            $res = Http::timeout(self::HTTP_TIMEOUT)->get(self::FORECAST_URL, [
                'latitude'  => $lat,
                'longitude' => $lng,
                'current'   => 'temperature_2m,apparent_temperature,precipitation,wind_speed_10m,weather_code',
                'daily'     => 'temperature_2m_max,temperature_2m_min,precipitation_probability_max,weather_code',
                'timezone'  => 'auto',
                'forecast_days' => 1,
            ]);

            $current = $res->json('current');
            if (! is_array($current) || ! isset($current['temperature_2m'])) {
                return null;
            }

            $code = (int) ($current['weather_code'] ?? 0);

            return [
                'temp_c'      => (int) round($current['temperature_2m']),
                'apparent_c'  => isset($current['apparent_temperature']) ? (int) round($current['apparent_temperature']) : null,
                'temp_min_c'  => isset($res->json('daily')['temperature_2m_min'][0]) ? (int) round($res->json('daily')['temperature_2m_min'][0]) : null,
                'temp_max_c'  => isset($res->json('daily')['temperature_2m_max'][0]) ? (int) round($res->json('daily')['temperature_2m_max'][0]) : null,
                'precip_prob' => $res->json('daily')['precipitation_probability_max'][0] ?? null,
                'wind_kmh'    => isset($current['wind_speed_10m']) ? (int) round($current['wind_speed_10m']) : null,
                'code'        => $code,
                'description' => $this->describe($code),
                'emoji'       => $this->emoji($code),
            ];
        } catch (\Throwable $e) {
            Log::warning('WeatherService fetch failed: ' . $e->getMessage());
            return null;
        }
    }

    /** WMO weather code → German description. */
    private function describe(int $code): string
    {
        return match (true) {
            $code === 0                       => 'Klar',
            in_array($code, [1, 2])           => 'Teils bewölkt',
            $code === 3                       => 'Bewölkt',
            in_array($code, [45, 48])         => 'Nebel',
            in_array($code, [51, 53, 55])     => 'Nieselregen',
            in_array($code, [56, 57])         => 'Gefrierender Niesel',
            in_array($code, [61, 63, 65])     => 'Regen',
            in_array($code, [66, 67])         => 'Gefrierender Regen',
            in_array($code, [71, 73, 75, 77]) => 'Schnee',
            in_array($code, [80, 81, 82])     => 'Regenschauer',
            in_array($code, [85, 86])         => 'Schneeschauer',
            in_array($code, [95, 96, 99])     => 'Gewitter',
            default                           => 'Wechselhaft',
        };
    }

    /** WMO weather code → emoji. */
    private function emoji(int $code): string
    {
        return match (true) {
            $code === 0                                 => '☀️',
            in_array($code, [1, 2])                     => '🌤️',
            $code === 3                                 => '☁️',
            in_array($code, [45, 48])                   => '🌫️',
            in_array($code, [51, 53, 55, 56, 57])       => '🌦️',
            in_array($code, [61, 63, 65, 66, 67, 80, 81, 82]) => '🌧️',
            in_array($code, [71, 73, 75, 77, 85, 86])   => '❄️',
            in_array($code, [95, 96, 99])               => '⛈️',
            default                                     => '🌥️',
        };
    }
}
