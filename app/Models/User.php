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
    'garmin_email', 'garmin_session', 'ai_daily_limit',
    'newsletter_opt_in', 'unsubscribe_token',
    'telegram_chat_id', 'telegram_link_token', 'telegram_linked_at', 'calendar_token'])]
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
     * Kommen Erholungswerte von Garmin an — per Push ueber die offizielle
     * Verbindung (mit Health-Freigabe) oder ueber den alten Login.
     */
    public function receivesGarminHealth(): bool
    {
        return ! empty($this->garmin_session)
            || (bool) $this->garminConnection?->allows(GarminConnection::HEALTH);
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
