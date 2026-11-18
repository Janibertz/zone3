<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminInsights;
use Inertia\Inertia;
use Inertia\Response;

/** KI-Kosten im Monat mit Prognose gegen das Plattformlimit (18.11.2026). */
class AdminAiCostController extends Controller
{
    public function __invoke(AdminInsights $insights): Response
    {
        return Inertia::render('Admin/AiCosts/Index', [
            'costs' => $insights->aiCostDetails(),
        ]);
    }
}
