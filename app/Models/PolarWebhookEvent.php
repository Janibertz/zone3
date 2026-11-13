<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Jeder Aufruf von Polar, vor jeder Filterung — beantwortet „ruft Polar überhaupt an?“. */
class PolarWebhookEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['event', 'polar_user_id', 'entity_id', 'outcome'];
}
