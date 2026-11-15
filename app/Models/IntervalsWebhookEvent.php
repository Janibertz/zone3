<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Jeder Aufruf von intervals.icu, vor jeder Filterung. */
class IntervalsWebhookEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['type', 'athlete_id', 'entity_id', 'outcome'];
}
