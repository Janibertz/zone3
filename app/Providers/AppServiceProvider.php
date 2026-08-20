<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Jede Nachricht des Coaches loest eine Benachrichtigung aus —
        // zentral, damit keine Quelle es vergisst.
        \App\Models\CoachMessage::observe(\App\Observers\CoachMessageObserver::class);
    }
}
