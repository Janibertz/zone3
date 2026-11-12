<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Einwilligung in die Übermittlung an OpenAI (App Store, Guideline
 * 5.1.2(i)). Neue Konten geben sie im Onboarding beim Coach; wer vorher
 * angefangen hat, wird einmal gefragt ({@see resources/js/Components/Consent/AiConsentSheet.vue}).
 */
class AiConsentController extends Controller
{
    public function store(Request $request)
    {
        $request->validate(['ai_consent' => 'accepted']);

        $user = $request->user();
        if (! $user->ai_coach_consent_at) {
            $user->forceFill(['ai_coach_consent_at' => now()])->save();
        }

        return back();
    }
}
