<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\CorosProbe;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\AppSetting;
use App\Models\Coach;
use App\Models\InstagramPublication;
use App\Models\Invitation;
use App\Models\Newsletter;
use App\Models\StrengthExercise;
use App\Models\User;
use App\Models\Workout;
use App\Services\Admin\AdminInsights;
use App\Services\OnboardingTexts;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * „Mehr“ im Admin (18.11.2026): eine Seite statt eines Sheets, wie Mehr in
 * der App — gruppiert, rechts grau der Stand.
 */
class AdminMoreController extends Controller
{
    public function __invoke(AdminInsights $insights): Response
    {
        $open      = Invitation::usable()->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', Invitation::REFERRAL_SOURCE))->count();
        $referrals = Invitation::usable()->where('source', Invitation::REFERRAL_SOURCE)->count();
        $topSource = User::whereNotNull('signup_source')->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('signup_source, COUNT(*) as n')->groupBy('signup_source')->orderByDesc('n')->value('signup_source');

        $exercises = StrengthExercise::count();
        $noVideo   = StrengthExercise::where('active', true)->whereNull('youtube_id')->count();
        $custom    = collect(OnboardingTexts::forAdmin())->where('custom', true)->count();
        $drafts    = Newsletter::whereNull('sent_at')->count();

        try {
            $probeFiles = count(Storage::disk('local')->files(CorosProbe::DIR));
        } catch (\Throwable) {
            $probeFiles = 0;
        }

        return Inertia::render('Admin/More', [
            'values' => [
                'access'      => $insights->accessLabel(),
                'invitations' => "{$open} offen · {$referrals} Freunde-Links",
                'sources'     => $topSource ? "{$topSource} vorn" : 'noch keine',
                'coaches'     => (string) Coach::count(),
                'exercises'   => $exercises . ($noVideo ? " · {$noVideo} ohne Video" : ''),
                'workouts'    => (string) Workout::where('is_public', true)->count(),
                'onboarding'  => $custom === 0 ? 'Vorgabe' : ($custom === 1 ? '1 geändert' : "{$custom} geändert"),
                'push'        => 'an alle, Gruppe oder einen',
                'instagram'   => ($n = InstagramPublication::where('status', InstagramPublication::NOTIFIED)->count()) ? "{$n} Story offen" : 'Plan und Stories',
                'newsletter'  => $drafts === 0 ? 'kein Entwurf' : ($drafts === 1 ? '1 Entwurf' : "{$drafts} Entwürfe"),
                'activities'  => number_format(Activity::count(), 0, ',', '.'),
                'settings'    => AppSetting::get('maintenance_mode', '0') === '1' ? 'Wartung an' : 'Wartung aus',
                'aiCosts'     => number_format($insights->aiCosts()['month'], 2, ',', '.') . ' € im ' . now()->locale('de')->isoFormat('MMMM'),
                'probe'       => $probeFiles === 1 ? '1 Datei' : "{$probeFiles} Dateien",
            ],
        ]);
    }
}
