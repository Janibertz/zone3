<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Jeder Aufruf von ROUVY, vor jeder Filterung. */
class RouvyWebhookEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['webhook_id', 'type', 'owner_id', 'entity_id', 'outcome'];
}
