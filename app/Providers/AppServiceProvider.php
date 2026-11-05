<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Der Katalog merkt sich die Übungen — je Anfrage bzw. je Job, damit
        // der Queue-Worker eine Änderung im Admin beim nächsten Job sieht.
        $this->app->scoped(\App\Services\Strength\StrengthCatalog::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {

        // Jede Nachricht des Coaches loest eine Benachrichtigung aus —
        // zentral, damit keine Quelle es vergisst.
        \App\Models\CoachMessage::observe(\App\Observers\CoachMessageObserver::class);

        // Vorschlaege des Coaches gehen mit Knoepfen nach Telegram.
        \App\Models\SessionRecommendation::observe(\App\Observers\SessionRecommendationObserver::class);
        \App\Models\TrainingSession::observe(\App\Observers\TrainingSessionObserver::class);
    }
}
