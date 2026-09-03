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
    /**
     * Die App im Voraus laden — aber nur fuer angemeldete Nutzer.
     *
     * `Vite::prefetch()` stand global im AppServiceProvider. Damit hing an
     * jeder Seite ein Rattenschwanz von 61 prefetch-Links, auch an der
     * Startseite: Wer zone3.run zum ersten Mal aufruft und noch gar kein
     * Konto hat, lud im Hintergrund die komplette Anwendung herunter —
     * Dashboard, Aktivitaeten, Workouts, Kalender. Auf dem Telefon ueber
     * Mobilfunk ist das genau die Wartezeit, die niemand versteht.
     *
     * Wer angemeldet ist, profitiert davon: dort ist der naechste Klick
     * wirklich eine dieser Seiten.
     */
    public function handle(Request $request, \Closure $next)
    {
        if ($request->user()) {
            \Illuminate\Support\Facades\Vite::prefetch(concurrency: 3);
        }

        return parent::handle($request, $next);
    }

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
                // Laeuft gerade eine Uebernahme? Dann gehoert das sichtbar
                // auf jede Seite — sonst haelt man fremde Daten fuer eigene
                // und aendert im Zweifel etwas am falschen Account.
                'impersonating'  => $request->session()->has(
                    \App\Http\Controllers\Admin\AdminImpersonationController::SESSION_KEY
                ),
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
