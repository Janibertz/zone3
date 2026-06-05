<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\User;
use App\Services\WeatherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WeatherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function forecastResponse(int $code = 0, float $temp = 27.3): array
    {
        return [
            'current' => [
                'temperature_2m'       => $temp,
                'apparent_temperature' => 29.1,
                'precipitation'        => 0,
                'wind_speed_10m'       => 12.0,
                'weather_code'         => $code,
            ],
            'daily' => [
                'temperature_2m_max'             => [28.0],
                'temperature_2m_min'             => [16.0],
                'precipitation_probability_max'  => [40],
                'weather_code'                   => [$code],
            ],
        ];
    }

    public function test_uses_latest_run_coordinates_and_parses_weather(): void
    {
        Http::fake([
            'api.open-meteo.com/*' => Http::response($this->forecastResponse(61, 14.0)),
        ]);

        $user = User::factory()->create();
        Activity::create([
            'user_id'      => $user->id,
            'strava_id'    => 9001,
            'name'         => 'Morgenlauf',
            'type'         => 'Run',
            'distance'     => 8000,
            'moving_time'  => 2400,
            'elapsed_time' => 2400,
            'start_date'   => now()->subDay(),
            'start_lat'    => 48.1372,
            'start_lng'    => 11.5755,
        ]);

        $weather = app(WeatherService::class)->forUser($user);

        $this->assertNotNull($weather);
        $this->assertSame(14, $weather['temp_c']);
        $this->assertSame('Regen', $weather['description']);
        $this->assertSame('🌧️', $weather['emoji']);
        $this->assertSame(40, $weather['precip_prob']);
    }

    public function test_falls_back_to_geocoded_profile_city(): void
    {
        Http::fake([
            'geocoding-api.open-meteo.com/*' => Http::response([
                'results' => [['latitude' => 48.13, 'longitude' => 11.57, 'name' => 'München']],
            ]),
            'api.open-meteo.com/*' => Http::response($this->forecastResponse(0, 25.0)),
        ]);

        $user = User::factory()->create(['location' => 'München']);
        // no activities with coordinates → must geocode

        $weather = app(WeatherService::class)->forUser($user);

        $this->assertNotNull($weather);
        $this->assertSame('Klar', $weather['description']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'geocoding-api.open-meteo.com'));
    }

    public function test_returns_null_without_location(): void
    {
        $user = User::factory()->create(['location' => null]);

        $this->assertNull(app(WeatherService::class)->forUser($user));
    }

    public function test_degrades_gracefully_on_api_error(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response('', 500)]);

        $user = User::factory()->create();
        Activity::create([
            'user_id'      => $user->id,
            'strava_id'    => 9002,
            'name'         => 'Lauf',
            'type'         => 'Run',
            'distance'     => 5000,
            'moving_time'  => 1500,
            'elapsed_time' => 1500,
            'start_date'   => now(),
            'start_lat'    => 52.52,
            'start_lng'    => 13.405,
        ]);

        $this->assertNull(app(WeatherService::class)->forUser($user));
    }
}
