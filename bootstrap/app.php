<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware(['web', 'auth', 'admin'])
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->validateCsrfTokens(except: [
            'strava/webhook',
            'garmin/webhook/*',
            'polar/webhook',
            'whoop/webhook',
            'telegram/webhook',
            'webhook/github',
            // Apple schickt die Anmeldung als Formular von appleid.apple.com;
            // geschützt über state + nonce im Cache.
            'auth/apple/callback',
            // Die App meldet Live-Activity-Tokens ohne Session; der Widget-Token schützt.
            'widget/*/live-activity',
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\CheckMaintenanceMode::class,
            \App\Http\Middleware\EnsureNotWaitlisted::class,
            \App\Http\Middleware\RememberSignupSource::class,
        ]);

        $middleware->alias([
            'onboarding' => \App\Http\Middleware\EnsureOnboardingComplete::class,
            'admin'      => \App\Http\Middleware\EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
