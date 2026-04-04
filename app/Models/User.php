<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Goal;
use App\Models\StravaAccount;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'onboarding_completed_at', 'is_admin', 'is_active'])]
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
            'email_verified_at'        => 'datetime',
            'onboarding_completed_at'  => 'datetime',
            'is_admin'                 => 'boolean',
            'is_active'                => 'boolean',
            'password'                 => 'hashed',
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
}
