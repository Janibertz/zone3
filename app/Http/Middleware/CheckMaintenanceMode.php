<?php

namespace App\Http\Middleware;

use App\Models\AppSetting;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CheckMaintenanceMode
{
    public function handle(Request $request, Closure $next)
    {
        if (AppSetting::get('maintenance_mode') !== '1') {
            return $next($request);
        }

        // Admins pass through
        if ($request->user()?->is_admin) {
            return $next($request);
        }

        // API / JSON requests
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Wartungsmodus aktiv.'], 503);
        }

        return Inertia::render('Maintenance')
            ->toResponse($request)
            ->setStatusCode(503);
    }
}
