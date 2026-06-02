<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiLog extends Model
{
    protected $fillable = [
        'user_id',
        'call_type',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'cost_eur',
        'duration_ms',
        'prompt_preview',
        'response_preview',
        'full_prompt',
        'full_response',
        'status',
        'error_message',
    ];

    protected $casts = [
        'prompt_tokens'     => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens'      => 'integer',
        'cost_eur'          => 'float',
        'duration_ms'       => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function todayCountForUser(int $userId): int
    {
        return static::where('user_id', $userId)
            ->where('status', 'success')
            ->whereDate('created_at', today())
            ->count();
    }

    /**
     * GPT-4o pricing (EUR, as of 2025): $2.50/1M input, $10.00/1M output
     * Converted at ~0.92 USD/EUR
     */
    public static function calculateCost(string $model, int $promptTokens, int $completionTokens): float
    {
        $rates = [
            'gpt-4o'      => ['input' => 2.50 * 0.92 / 1_000_000, 'output' => 10.00 * 0.92 / 1_000_000],
            'gpt-4o-mini' => ['input' => 0.15 * 0.92 / 1_000_000, 'output' => 0.60 * 0.92 / 1_000_000],
        ];

        $rate = $rates[$model] ?? $rates['gpt-4o'];

        return round(
            ($promptTokens * $rate['input']) + ($completionTokens * $rate['output']),
            6
        );
    }

    public function getCostFormattedAttribute(): string
    {
        if ($this->cost_eur < 0.001) {
            return number_format($this->cost_eur * 100, 4) . ' ct';
        }
        return number_format($this->cost_eur, 4) . ' €';
    }

    public function getCallTypeLabelAttribute(): string
    {
        return match ($this->call_type) {
            'recommendation'       => 'Tagesempfehlung',
            'adjust_recommendation'=> 'Empfehlung anpassen',
            'plan'                 => 'Trainingsplan',
            'event_plan'           => 'Event-Trainingsplan',
            'weekly_review'        => 'Wochenrückblick',
            'pace_zones'           => 'Pace-Zonen',
            'threshold_pace'       => 'Schwellenpace',
            'nutrition'            => 'Ernährungstipps',
            'adjust_session'       => 'Session anpassen',
            'goal_analysis'        => 'Ziel-Analyse',
            'suggestions'          => 'Vorschläge',
            default                => $this->call_type,
        };
    }
}
