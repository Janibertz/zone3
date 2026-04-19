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

        try {
            $cookies = $garmin->login($request->email, $request->password);
        } catch (\Throwable $e) {
            return redirect()->route('profile.edit')
                ->with('status', 'garmin-error')
                ->with('garmin_error', $e->getMessage());
        }

        $account = GarminAccount::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'email'             => $request->email,
                'password'          => $request->password,
                'cookies'           => $cookies,
                'cookies_expire_at' => now()->addHours(23),
                'connected_at'      => now(),
            ]
        );

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

        try {
            $garmin->ensureFreshSession($account);
            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }
    }
}
