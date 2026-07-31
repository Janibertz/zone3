<?php

namespace App\Http\Middleware;

use App\Models\Coach;
use App\Models\TrainingPlan;
use App\Models\User;
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
                'user'           => $this->userProps($request->user()),
                'isAdmin'        => (bool) $request->user()?->is_admin,
                'garminEmail'    => $request->user()?->garmin_email,
                'garminConnected'=> !empty($request->user()?->garmin_session),
            ],
            'coach' => function () use ($request) {
                $user = $request->user();
                if (! $user) return null;
                $coach = $user->coach;
                if (! $coach) return null;
                return [
                    'id'              => $coach->id,
                    'name'            => $coach->name,
                    'specialty'       => $coach->specialty,
                    'tagline'         => $coach->tagline,
                    'avatar_color'    => $coach->avatar_color,
                    'avatar_initials' => $coach->avatar_initials,
                ];
            },
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

    /**
     * Whitelist der User-Felder, die ins Frontend dürfen.
     *
     * Bewusst explizit statt `$request->user()`: das ganze Model zu teilen hat
     * u. a. die entschlüsselte `garmin_session` in jedes Inertia-Payload gelegt.
     *
     * @return array<string, mixed>|null
     */
    protected function userProps(?User $user): ?array
    {
        if (! $user) return null;

        return [
            'id'                => $user->id,
            'name'              => $user->name,
            'email'             => $user->email,
            'email_verified_at' => $user->email_verified_at,
            'avatar'            => $user->avatar,
            'bio'               => $user->bio,
            'location'          => $user->location,
            'birth_year'        => $user->birth_year,
            'favorite_distance' => $user->favorite_distance,
            'newsletter_opt_in' => $user->newsletter_opt_in,
            'coach_id'          => $user->coach_id,
            'created_at'        => $user->created_at,
        ];
    }
}
