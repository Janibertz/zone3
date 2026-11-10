<?php

namespace App\Http\Middleware;

use App\Services\Auth\SignupSource;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Merkt sich `?ref=` / `utm_source` für die spätere Anmeldung. */
class RememberSignupSource
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET')) {
            SignupSource::remember($request);
        }

        return $next($request);
    }
}
