<?php

namespace App\Services;

use App\Models\StravaAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class StravaService
{
    protected string $clientId;
    protected string $clientSecret;
    protected string $redirectUri;

    public function __construct()
    {
        $this->clientId = config('services.strava.client_id');
        $this->clientSecret = config('services.strava.client_secret');
        $this->redirectUri = config('services.strava.redirect');
    }

    public function getAuthorizationUrl(?string $state = null): string
    {
        $params = [
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'approval_prompt' => 'auto',
            'scope' => 'read,activity:read_all',
        ];

        if ($state) {
            $params['state'] = $state;
        }

        return 'https://www.strava.com/oauth/authorize?' . http_build_query($params);
    }

    /**
     * Exchange an authorization code for tokens.
     *
     * @return array{access_token:string,refresh_token:string,expires_at:int,athlete:array}
     */
    public function exchangeCodeForToken(string $code): array
    {
        $response = Http::asForm()->post('https://www.strava.com/oauth/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
        ]);

        $response->throw();

        return $response->json();
    }

    public function refreshToken(StravaAccount $account): StravaAccount
    {
        $response = Http::asForm()->post('https://www.strava.com/oauth/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]);

        $response->throw();

        $data = $response->json();

        $account->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'token_expires_at' => Carbon::createFromTimestamp($data['expires_at']),
            'scope' => isset($data['scope']) ? explode(',', $data['scope']) : null,
        ]);

        return $account;
    }

    /**
     * Return a list of recent activities from Strava.
     *
     * @return array<int, array>
     */
    public function fetchRecentActivities(StravaAccount $account, int $perPage = 30): array
    {
        if ($account->token_expires_at && $account->token_expires_at->isPast()) {
            $account = $this->refreshToken($account);
        }

        $response = Http::withToken($account->access_token)
            ->get('https://www.strava.com/api/v3/athlete/activities', [
                'per_page' => $perPage,
            ]);

        $response->throw();

        $account->update(['last_synced_at' => Carbon::now()]);

        return $response->json();
    }

    /**
     * Fetch a single activity by ID from Strava.
     */
    public function fetchActivity(StravaAccount $account, int $activityId): ?array
    {
        if ($account->token_expires_at && $account->token_expires_at->isPast()) {
            $account = $this->refreshToken($account);
        }

        $response = Http::withToken($account->access_token)
            ->get("https://www.strava.com/api/v3/activities/{$activityId}");

        if ($response->failed()) return null;

        return $response->json();
    }
}
