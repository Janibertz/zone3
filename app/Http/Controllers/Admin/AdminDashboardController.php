<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminInsights;
use App\Services\SystemHealth;
use Inertia\Inertia;

/**
 * „Heute“ im Admin (Umbau 18.11.2026): was eine Antwort braucht, das
 * Tester-Ziel mit Trichter, die KI-Kosten und wer still geworden ist.
 * Die alten Zähler (Bestand, Tagesform-Trend, Coach-Verteilung,
 * Monatsbalken) sind weg — sie haben nie eine Entscheidung ausgelöst.
 */
class AdminDashboardController extends Controller
{
    public function __construct(
        private readonly SystemHealth $health,
        private readonly AdminInsights $insights,
    ) {}

    public function index()
    {
        $groups = $this->insights->failureGroups();

        return Inertia::render('Admin/Dashboard', [
            'needsYou'      => $this->insights->needsYou(),
            'goal'          => $this->insights->testerGoal(),
            'withoutPlan'   => $this->insights->onboardedWithoutPlan(),
            'aiCosts'       => $this->insights->aiCosts(),
            'quiet'         => $this->insights->quiet(),
            'providers'     => $this->insights->providers(),
            'systemHealth'  => $this->health->summary(),
            'failure'       => $groups ? [
                'count' => array_sum(array_column($groups, 'count')),
                'since' => collect($groups)->min('first'),
            ] : null,
        ]);
    }
}
