<?php

namespace App\Http\Controllers;

use App\Services\OpenAIService;
use App\Services\WrappedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class WrappedController extends Controller
{
    public function __construct(protected WrappedService $wrapped) {}

    public function index(Request $request): Response
    {
        $user    = $request->user();
        $periods = $this->wrapped->availablePeriods($user);
        $year    = $periods['years'][0] ?? (int) now()->year; // most recent year with data

        return Inertia::render('Wrapped', [
            'initialStats'     => $this->wrapped->generate($user, 'year', $year),
            'availablePeriods' => $periods,
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $data = $this->validatePeriod($request);

        return response()->json([
            'stats' => $this->wrapped->generate($request->user(), $data['period'], $data['year'] ?? null, $data['month'] ?? null),
        ]);
    }

    public function review(Request $request, OpenAIService $openAI): JsonResponse
    {
        $data  = $this->validatePeriod($request);
        $user  = $request->user();
        $stats = $this->wrapped->generate($user, $data['period'], $data['year'] ?? null, $data['month'] ?? null);

        if (empty($stats['has_data'])) {
            return response()->json(['text' => null]);
        }

        // Cache keyed by the numbers — regenerates only when the stats change.
        $hash = md5(json_encode($stats['totals']) . json_encode($stats['prs'] ?? []) . $stats['period_label']);
        $key  = "wrapped_review:{$user->id}:{$stats['period']}:{$stats['period_label']}:{$hash}";

        $text = Cache::remember($key, now()->addDays(14), function () use ($openAI, $user, $stats) {
            $openAI->withCoach($user->coach?->personality_prompt)->forUser($user->id);
            return $openAI->generateWrappedReview($stats, $stats['period_label']);
        });

        return response()->json(['text' => $text]);
    }

    private function validatePeriod(Request $request): array
    {
        return $request->validate([
            'period' => 'required|in:year,month',
            'year'   => 'nullable|integer|min:2000|max:2100',
            'month'  => 'nullable|integer|min:1|max:12',
        ]);
    }
}
