<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Coach;
use App\Models\Event;
use App\Models\Goal;
use App\Models\TrainingSession;
use App\Models\StravaAccount;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'onboarding_completed_at', 'is_admin', 'is_active',
    'apple_sub', 'waitlisted_at', 'signup_source',
    'push_notifications_enabled', 'wellbeing_reminder_time', 'notify_threshold_pace', 'notify_plan_updated',
    'notify_monthly_review',
    'avatar', 'bio', 'location', 'birth_year', 'favorite_distance', 'coach_id',
    'ai_daily_limit', 'ai_daily_budget_eur', 'activity_source', 'garmin_workout_sync', 'apple_watch_sync', 'strava_allowed',
    'newsletter_opt_in', 'unsubscribe_token',
    'telegram_chat_id', 'telegram_link_token', 'telegram_linked_at', 'calendar_token',
    'dashboard_layout', 'apple_health_consent_at', 'apple_health_synced_at', 'apple_activities_synced_at',
    'moment_week_seen', 'moment_race_seen'])]
#[Hidden(['password', 'remember_token', 'unsubscribe_token', 'apple_sub',
    'telegram_chat_id', 'telegram_link_token', 'calendar_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Alles andere hängt per `cascadeOnDelete` am Konto. Nur zwei Tabellen
     * sind `nullOnDelete`, damit Statistik und Webhook-Protokoll das Konto
     * überleben — `ai_logs` trägt dabei den vollständigen Prompt samt
     * Trainingsdaten und Chatnachrichten. /privacy verspricht, dass mit dem
     * Konto alles verschwindet. Beide Löschwege (Profil, Admin) laufen über
     * `$user->delete()`, also gehört es hierher.
     */
    protected static function booted(): void
    {
        static::deleting(function (User $user) {
            AiLog::where('user_id', $user->id)->delete();
            StravaWebhookEvent::where('user_id', $user->id)->delete();
            GarminWebhookEvent::where('user_id', $user->id)->delete();
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at'             => 'datetime',
            'onboarding_completed_at'       => 'datetime',
            'is_admin'                      => 'boolean',
            'is_active'                     => 'boolean',
            'waitlisted_at'                 => 'datetime',
            'seen_app_on'                   => 'date',
            'seen_homescreen_on'            => 'date',
            'seen_browser_on'               => 'date',
            'password'                      => 'hashed',
            'push_notifications_enabled'    => 'boolean',
            'notify_threshold_pace'         => 'boolean',
            'notify_plan_updated'           => 'boolean',
            'notify_monthly_review'         => 'boolean',
            'newsletter_opt_in'             => 'boolean',
            'telegram_linked_at'            => 'datetime',
            'dashboard_layout'              => 'array',
            'garmin_workout_sync'           => 'boolean',
            'apple_watch_sync'              => 'boolean',
            'strava_allowed'                => 'boolean',
            'starter_plan_viewed_at'        => 'datetime',
            'starter_dismissed_at'          => 'datetime',
            'beta_badge_at'                 => 'datetime',
            'moment_week_seen'              => 'date',
            'apple_health_consent_at'       => 'datetime',
            'apple_health_synced_at'        => 'datetime',
            'apple_activities_synced_at'    => 'datetime',
        ];
    }

    /** Wartet das Konto auf die Freischaltung? Siehe {@see \App\Services\Auth\Access}. */
    public function isWaitlisted(): bool
    {
        return $this->waitlisted_at !== null && ! $this->is_admin;
    }

    /**
     * Darf der Athlet Strava sehen und verbinden?
     *
     * Neue Athleten nicht (Jan, 03.11.2026: Strava hat keine freien Plätze,
     * die Werbung holt Garmin-Läufer); ein Admin schaltet es frei. Wer
     * schon verbunden ist, behält es.
     */
    public function canUseStrava(): bool
    {
        return (bool) $this->strava_allowed || $this->stravaAccount()->exists();
    }

    /** Ob der Coach diesen Athleten auch per Telegram erreicht. */
    public function hasTelegram(): bool
    {
        return $this->telegram_chat_id !== null;
    }

    public function coach()
    {
        return $this->belongsTo(Coach::class);
    }

    public function stravaAccount()
    {
        return $this->hasOne(StravaAccount::class);
    }

    public function garminConnection()
    {
        return $this->hasOne(GarminConnection::class);
    }

    public function corosConnection()
    {
        return $this->hasOne(CorosConnection::class);
    }

    /** Legt Zone3 die naechsten Plantage selbst in den COROS-Kalender? */
    public function syncsPlanToCoros(): bool
    {
        return (bool) $this->corosConnection?->workout_sync;
    }

    /**
     * Woher Aktivitäten kommen. Eine Quelle pro Athlet (Entscheidung vom
     * 27.10.2026), damit nie zwei Wege dieselbe Einheit anlegen.
     *
     * Die eigene Wahl gilt, solange die Quelle liefern kann: Strava
     * verbunden, bei Garmin mit Aktivitäts-Freigabe. Sonst die Quelle, die
     * liefern kann — wer Garmin wählt und die Freigabe in Garmin Connect
     * abschaltet, fällt auf Strava zurück, statt nichts mehr zu bekommen.
     */
    public function activitySource(): string
    {
        $usable = $this->usableActivitySources();

        if (in_array($this->activity_source, $usable, true)) {
            return $this->activity_source;
        }

        return $usable[0] ?? Activity::SOURCE_STRAVA;
    }

    /**
     * Die Quellen, die gerade liefern können, Strava zuerst.
     *
     * @return list<string>
     */
    public function usableActivitySources(): array
    {
        return array_values(array_filter([
            $this->stravaAccount ? Activity::SOURCE_STRAVA : null,
            $this->garminConnection?->allows(GarminConnection::ACTIVITY) ? Activity::SOURCE_GARMIN : null,
            // COROS liest nur mit Einwilligung (Schalter „Daten übernehmen“).
            $this->corosConnection?->readsData() ? Activity::SOURCE_COROS : null,
            // Apple Health liest die iOS-App, mit Einwilligung.
            $this->apple_health_consent_at ? Activity::SOURCE_APPLE : null,
        ]));
    }

    /**
     * Kommen Erholungswerte an — von Garmin per Push (Health-Freigabe), von
     * COROS per Abruf oder aus Apple Health über die iOS-App (beide mit
     * Einwilligung). Alle schreiben in `garmin_daily_metrics`.
     */
    public function receivesHealthMetrics(): bool
    {
        return $this->receivesWatchHealthMetrics() || $this->apple_health_consent_at !== null;
    }

    /**
     * Erholungswerte von einer Uhr mit eigener Anbindung (Garmin, COROS).
     * Die haben Vorrang vor Apple Health: zwei Quellen messen eine Nacht nie
     * gleich, und Apple misst die HRV anders (SDNN statt RMSSD).
     */
    public function receivesWatchHealthMetrics(): bool
    {
        return (bool) $this->garminConnection?->allows(GarminConnection::HEALTH)
            || (bool) $this->corosConnection?->readsData();
    }

    /** Workouts gehen ueber die Training API — offizielle Verbindung mit Workout-Freigabe. */
    public function sendsWorkoutsViaGarminApi(): bool
    {
        return (bool) $this->garminConnection?->allows(GarminConnection::WORKOUTS);
    }

    /**
     * Die Apple Watch als Ziel für Einheiten (Knopf, WorkoutKit). Nicht,
     * solange Zone3 den Plan selbst auf die Garmin-Uhr legt — sonst stünden
     * dieselben Läufe auf zwei Uhren. Ist „Plan automatisch auf die Uhr“
     * bei Garmin aus, gibt es die Watch auch neben Garmin (Jan, 09.11.2026;
     * vorher sperrte schon die Workout-Freigabe allein).
     */
    public function offersAppleWatch(): bool
    {
        return ! $this->syncsPlanToGarmin();
    }

    /** Haelt Zone3 die naechsten Plantage selbst im Garmin-Kalender aktuell? */
    public function syncsPlanToGarmin(): bool
    {
        // Ein frisch angelegtes Modell kennt den Datenbank-Standard (an) noch nicht.
        return ($this->garmin_workout_sync ?? true) && $this->sendsWorkoutsViaGarminApi();
    }

    public function goals()
    {
        return $this->hasMany(Goal::class);
    }

    public function events()
    {
        return $this->hasMany(Event::class);
    }

    public function trainingSessions()
    {
        return $this->hasMany(TrainingSession::class);
    }

    public function activeTrainingPlan()
    {
        return $this->hasOne(TrainingPlan::class)->where('is_active', true)->latest();
    }

    /**
     * Der aktive Plan beginnt erst später (Planstart aus dem Interview): bis
     * dahin überbrückt der Tagesvorschlag (09.11.2026). Null, wenn kein
     * Plan aktiv ist oder er schon läuft.
     *
     * @return array{race: string, date: string, days: int}|null
     */
    public function planStartsLater(): ?array
    {
        $event = $this->activeTrainingPlan?->event;
        $start = $event?->plan_start_date;
        if (! $start || ! $start->isAfter(today())) {
            return null;
        }

        return [
            'race' => $event->name,
            'date' => $start->locale('de')->isoFormat('dd, D. MMMM'),
            'days' => (int) today()->diffInDays($start),
        ];
    }

    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    public function runnerProfile()
    {
        return $this->hasOne(RunnerProfile::class);
    }

    public function wellbeingEntries()
    {
        return $this->hasMany(WellbeingEntry::class);
    }

    public function pushSubscriptions()
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
