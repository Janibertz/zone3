<?php

namespace App\Http\Controllers;

use App\Models\GarminAccount;
use App\Services\GarminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GarminController extends Controller
{
    public function connect(Request $request, GarminService $garmin): RedirectResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        // Save first so we can test
        $account = GarminAccount::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'email'        => $request->email,
                'password'     => $request->password,
                'connected_at' => now(),
            ]
        );

        // Test credentials against Garmin via Python microservice
        $result = $garmin->testConnection($account);
        if (! ($result['ok'] ?? false)) {
            $account->delete();
            return redirect()->route('profile.edit')
                ->with('status', 'garmin-error')
                ->with('garmin_error', $result['error'] ?? 'Login fehlgeschlagen.');
        }

        return redirect()->route('profile.edit')->with('status', 'garmin-connected');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $request->user()->garminAccount?->delete();
        return redirect()->route('profile.edit')->with('status', 'garmin-disconnected');
    }

    public function test(Request $request, GarminService $garmin): JsonResponse
    {
        $account = $request->user()->garminAccount;
        if (! $account) {
            return response()->json(['ok' => false, 'message' => 'Nicht verbunden.']);
        }

        $result = $garmin->testConnection($account);
        return response()->json([
            'ok'      => $result['ok'] ?? false,
            'message' => $result['error'] ?? ($result['display_name'] ?? 'OK'),
        ]);
    }
}
