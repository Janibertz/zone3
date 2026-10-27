<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Anruf von Garmin — festgehalten, egal was daraus wurde.
 *
 * Dieselbe Idee wie `StravaWebhookEvent`: die Zeile entsteht vor jeder
 * Filterung und beantwortet die Frage, ob ueberhaupt etwas ankam.
 */
class GarminWebhookEvent extends Model
{
    protected $fillable = ['type', 'garmin_user_id', 'user_id', 'outcome', 'note'];

    public const OUTCOME_DEREGISTERED       = 'deregistered';
    public const OUTCOME_PERMISSIONS        = 'permissions_updated';
    public const OUTCOME_UNKNOWN_USER       = 'unknown_user';
    public const OUTCOME_STILL_CONNECTED    = 'still_connected';
    public const OUTCOME_CHECK_FAILED       = 'check_failed';

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
