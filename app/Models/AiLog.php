<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class AiLog extends Model
{
    protected $fillable = [
        'user_id',
        'call_type',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'cached_tokens',
        'reasoning_tokens',
        'service_tier',
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
        'cached_tokens'     => 'integer',
        'reasoning_tokens'  => 'integer',
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
     * Was heute schon ausgegeben wurde, in Euro — fuer alle oder einen Athleten.
     *
     * Die Grundlage der Tagesbudgets in {@see \App\Services\AI\OpenAIClient::blockReason()}.
     * Fehlgeschlagene Aufrufe zaehlen mit: auch sie koennen Token kosten.
     */
    public static function todayCost(?int $userId = null): float
    {
        return (float) static::query()
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->whereDate('created_at', today())
            ->sum('cost_eur');
    }

    /** Umrechnungskurs fuer die Listenpreise, die OpenAI in USD nennt. */
    private const USD_TO_EUR = 0.92;

    /**
     * Listenpreise in USD je 1M Token: [Eingabe, Ausgabe].
     *
     * Diese Tabelle kannte lange nur `gpt-4o` und `gpt-4o-mini` — und fiel
     * fuer alles andere still auf `gpt-4o` zurueck. Gelaufen sind seit
     * Monaten `gpt-5.5` und `gpt-5.4-mini`, also war JEDE Euro-Zahl im
     * AI-Log mit den Preisen eines Modells gerechnet, das gar nicht im
     * Einsatz war. Auffallen konnte das nicht: eine falsche Zahl sieht aus
     * wie eine richtige.
     *
     * Deshalb gibt es jetzt keinen stillen Rueckfall mehr — ein unbekanntes
     * Modell kostet 0 und schreibt eine Warnung ins Log. Eine Luecke, die
     * man sieht, ist besser als eine Zahl, der man nicht trauen kann.
     *
     * Seit dem 28.10.2026 ist das die echte Rechnung: das Teilen der Daten
     * mit OpenAI (und damit das Freikontingent) ist aus. Die Tagesbudgets
     * im Client rechnen mit diesen Zahlen.
     *
     * Sol kostet 4/20 $ laut mehreren Quellen nur als Aktion bis etwa
     * 21.11.2026, danach 5/30 $. Dann hier nachziehen — sonst greifen die
     * Budgets zu spaet.
     */
    private const RATES_USD_PER_MTOK = [
        'gpt-6-astra'   => [10.00, 50.00],
        'gpt-5.6-sol'   => [ 4.00, 20.00],
        'gpt-5.6'       => [ 4.00, 20.00],   // Alias von Sol
        'gpt-5.6-terra' => [ 2.00, 12.00],
        'gpt-5.6-luna'  => [ 0.20,  1.20],
        'gpt-4o'        => [ 2.50, 10.00],
        'gpt-4o-mini'   => [ 0.15,  0.60],
    ];

    /** Gecachte Eingabe-Token kosten ein Zehntel des Eingabepreises. */
    private const CACHED_INPUT_FACTOR = 0.10;

    /** Flex kostet die Haelfte — auf Ein- und Ausgabe. */
    private const FLEX_FACTOR = 0.50;

    /**
     * @param int  $cachedTokens Teil von $promptTokens, der aus dem Cache kam
     * @param bool $flex         lief der Aufruf ueber Flex (`service_tier`)
     */
    public static function calculateCost(
        string $model,
        int $promptTokens,
        int $completionTokens,
        int $cachedTokens = 0,
        bool $flex = false,
    ): float {
        $rate = self::rateFor($model);

        if ($rate === null) {
            Log::warning('Kein Preis fuer dieses Modell hinterlegt', ['model' => $model]);

            return 0.0;
        }

        [$in, $out] = $rate;

        $cached   = min(max($cachedTokens, 0), $promptTokens);
        $uncached = $promptTokens - $cached;

        $usd = $uncached * $in
            + $cached * $in * self::CACHED_INPUT_FACTOR
            + $completionTokens * $out;

        if ($flex) {
            $usd *= self::FLEX_FACTOR;
        }

        return round($usd * self::USD_TO_EUR / 1_000_000, 6);
    }

    /**
     * Den Preis zu einer Modell-ID finden, auch mit Datumsanhang.
     *
     * Genau daran ist die alte Tabelle gescheitert: konfiguriert war
     * `gpt-5.5-2026-04-23`, in der Tabelle stand `gpt-5.5` nicht einmal —
     * und der exakte Schluesselvergleich fand nichts.
     *
     * @return array{0: float, 1: float}|null
     */
    private static function rateFor(string $model): ?array
    {
        $key = strtolower(trim($model));

        if (isset(self::RATES_USD_PER_MTOK[$key])) {
            return self::RATES_USD_PER_MTOK[$key];
        }

        // `gpt-5.6-sol-2026-08-01` -> `gpt-5.6-sol`
        $stripped = preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $key);

        return self::RATES_USD_PER_MTOK[$stripped] ?? null;
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
            'coach_chat'           => 'Coach-Chat',
            'session_review'       => 'Einheit-Review',
            'suggestions'          => 'Vorschläge',
            default                => $this->call_type,
        };
    }
}
