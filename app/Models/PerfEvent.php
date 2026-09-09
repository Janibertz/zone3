<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein gemessenes Ereignis: eine Anfrage, eine langsame Query, ein Job, ein
 * ausgehender Aufruf.
 *
 * Geschrieben wird ueber `Observability\Recorder`, gelesen ueber
 * `Observability\PerfDigest`. Direkt an diesem Modell zu hantieren ist
 * moeglich, aber die beiden Klassen sind die Stelle, an der die Regeln
 * stehen.
 */
class PerfEvent extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_REQUEST = 'request';
    public const TYPE_QUERY   = 'query';
    public const TYPE_JOB     = 'job';
    public const TYPE_HTTP    = 'http';

    protected $fillable = [
        'type', 'name', 'duration_ms', 'status',
        'query_count', 'query_ms', 'memory_kb', 'user_id', 'context',
    ];

    protected $casts = [
        'context'     => 'array',
        'created_at'  => 'datetime',
        'duration_ms' => 'integer',
        'query_count' => 'integer',
        'query_ms'    => 'integer',
        'memory_kb'   => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
