<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Jeder Aufruf von WHOOP, vor jeder Filterung (30 Tage). */
class WhoopWebhookEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['type', 'whoop_user_id', 'entity_id', 'outcome'];
}
