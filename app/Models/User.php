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
    'push_notifications_enabled', 'wellbeing_reminder_time', 'notify_threshold_pace', 'notify_plan_updated',
    'notify_monthly_review',
    'avatar', 'bio', 'location', 'birth_year', 'favorite_distance', 'coach_id',
    'garmin_email', 'garmin_session', 'ai_daily_limit', 'activity_source', 'garmin_workout_sync',
    'newsletter_opt_in', 'unsubscribe_token',
    'telegram_chat_id', 'telegram_link_token', 'telegram_linked_at', 'calendar_token',
    'dashboard_layout'])]
#[Hidden(['password', 'remember_token', 'garmin_session', 'garmin_email', 'unsubscribe_token',
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
            'password'                      => 'hashed',
            'push_notifications_enabled'    => 'boolean',
            'notify_threshold_pace'         => 'boolean',
            'notify_plan_updated'           => 'boolean',
            'notify_monthly_review'         => 'boolean',
            'garmin_session'                => 'encrypted',
            'newsletter_opt_in'             => 'boolean',
            'telegram_linked_at'            => 'datetime',
            'dashboard_layout'              => 'array',
            'garmin_workout_sync'           => 'boolean',
        ];
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
        ]));
    }

    /**
     * Kommen Erholungswerte von Garmin an — per Push ueber die offizielle
     * Verbindung (mit Health-Freigabe) oder ueber den alten Login.
     */
    public function receivesGarminHealth(): bool
    {
        return ! empty($this->garmin_session)
            || (bool) $this->garminConnection?->allows(GarminConnection::HEALTH);
    }

    /** Workouts gehen ueber die Training API — offizielle Verbindung mit Workout-Freigabe. */
    public function sendsWorkoutsViaGarminApi(): bool
    {
        return (bool) $this->garminConnection?->allows(GarminConnection::WORKOUTS);
    }

    /**
     * Gibt es einen Weg, Einheiten auf die Garmin-Uhr zu schicken — die
     * Training API oder, bis Schritt 6, der alte Login.
     */
    public function sendsWorkoutsToGarmin(): bool
    {
        return $this->sendsWorkoutsViaGarminApi() || ! empty($this->garmin_session);
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
