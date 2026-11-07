<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\OnboardingTexts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Die Sprechblasen des Coaches im Onboarding (Jan, 07.11.2026). */
class AdminOnboardingTextController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/OnboardingTexts/Index', [
            'texts'     => OnboardingTexts::forAdmin(),
            'maxLength' => OnboardingTexts::MAX_LENGTH,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (array_keys(OnboardingTexts::DEFAULTS) as $key) {
            $rules["texts.{$key}"] = ['nullable', 'string', 'max:' . OnboardingTexts::MAX_LENGTH];
        }
        $validated = $request->validate($rules);

        OnboardingTexts::save($validated['texts'] ?? []);

        return back()->with('success', 'Texte gespeichert.');
    }
}
