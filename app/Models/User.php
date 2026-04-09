<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Event;
use App\Models\Goal;
use App\Models\TrainingSession;
use App\Models\StravaAccount;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'onboarding_completed_at', 'is_admin', 'is_active',
    'push_notifications_enabled', 'wellbeing_reminder_time', 'notify_threshold_pace', 'notify_plan_updated'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
        ];
    }

    public function stravaAccount()
    {
        return $this->hasOne(StravaAccount::class);
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
}
