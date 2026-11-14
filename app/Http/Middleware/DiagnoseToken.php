<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zugang zu `/diagnose/*` nur mit `Authorization: Bearer <DIAGNOSE_TOKEN>`.
 * Ohne gesetzten Token gibt es die Endpunkte nicht (404), mit falschem
 * ebenfalls 404 — wer rät, erfährt nicht, dass es sie gibt. Jeder Abruf
 * steht im Log.
 */
class DiagnoseToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('services.diagnose.token');

        if (strlen($token) < 32 || ! hash_equals($token, (string) $request->bearerToken())) {
            abort(404);
        }

        Log::info('Diagnose-Abruf', ['path' => $request->path(), 'query' => $request->query(), 'ip' => $request->ip()]);

        return $next($request);
    }
}
