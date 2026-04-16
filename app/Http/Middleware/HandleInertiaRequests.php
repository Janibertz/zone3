<?php

namespace App\Http\Middleware;

use App\Models\TrainingPlan;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user'    => $request->user(),
                'isAdmin' => (bool) $request->user()?->is_admin,
            ],
            'activePlan' => function () use ($request) {
                if (! $request->user()) return null;
                $plan = TrainingPlan::where('user_id', $request->user()->id)
                    ->where('is_active', true)
                    ->whereHas('event', fn ($q) => $q->where('event_date', '>=', now()->toDateString()))
                    ->with('event:id,name')
                    ->first();
                if (! $plan) return null;
                return ['event_id' => $plan->event_id, 'event_name' => $plan->event->name ?? 'Trainingsplan'];
            },
        ];
    }
}
