<?php

namespace App\Http\Controllers;

use App\Models\RunnerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReturnToRunController extends Controller
{
    /**
     * Dismiss the current return-to-run phase. The dashboard card stays hidden
     * until a new break/injury starts after this timestamp.
     */
    public function dismiss(Request $request): JsonResponse
    {
        $profile = RunnerProfile::firstOrCreate(['user_id' => $request->user()->id]);
        $profile->update(['return_to_run_dismissed_at' => now()]);

        return response()->json(['success' => true]);
    }
}
