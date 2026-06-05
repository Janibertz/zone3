<?php

namespace App\Http\Controllers;

use App\Services\WeatherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeatherController extends Controller
{
    public function __construct(protected WeatherService $weather) {}

    /**
     * Today's weather at the user's training location for the dashboard chip.
     * Returns { weather: null } when no location/weather is available.
     */
    public function today(Request $request): JsonResponse
    {
        return response()->json([
            'weather' => $this->weather->forUser($request->user()),
        ]);
    }
}
