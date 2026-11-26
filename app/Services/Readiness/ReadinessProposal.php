<?php

namespace App\Services\Readiness;

use App\Models\SessionRecommendation;
use App\Models\TrainingSession;
use App\Models\User;
use App\Observers\SessionRecommendationObserver;
use App\Services\Run\SessionStructure;
use App\Services\WebPushService;
use App\Services\WeeklyPatternService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Bei niedriger Readiness schlägt der Coach eine leichtere Einheit vor
 * (seit 18.11.2026, Jan: „Annehmen oder ablehnen“).
 *
 *   · unter 25: Ruhetag statt der geplanten Einheit
 *   · 25–49:    ein lockerer Lauf statt einer harten Einheit
 *
 * Derselbe Weg wie beim Tagesform-Vorschlag: eine {@see SessionRecommendation},
 * nichts ändert sich, bis der Athlet annimmt. Höchstens ein Vorschlag je
 * Einheit und Tag — gab es heute schon einen (auch aus der Tagesform, auch
 * abgelehnt), bleibt es dabei. Rennen werden nie angefasst.
 */
class ReadinessProposal
{
    public function __construct(
        private readonly ReadinessService $readiness,
        private readonly SessionStructure $structure,
    ) {}

    public function check(User $user, ?CarbonImmutable $day = null): ?SessionRecommendation
    {
        $day = $day ?? CarbonImmutable::today();

        $r = $this->readiness->morning($user, $day);
        if (! $r || $r['score'] >= ReadinessService::EASY_BELOW) {
            return null;
        }

        $session = TrainingSession::where('user_id', $user->id)
            ->whereDate('planned_date', $day->toDateString())
            ->where('status', 'planned')
            ->whereNotIn('type', ['rest', 'race_prep', 'strength', 'core', 'mobility'])
            ->where(fn ($q) => $q->whereNull('training_plan_id')
                ->orWhereHas('trainingPlan', fn ($p) => $p->where('is_active', true)))
            ->orderBy('sort_order')
            ->first();

        if (! $session || ! SessionRecommendation::stillApplies($session)) {
            return null;
        }

        $already = SessionRecommendation::where('training_session_id', $session->id)
            ->whereDate('created_at', $day->toDateString())
            ->exists();
        if ($already) {
            return null;
        }

        $rest = $r['score'] < ReadinessService::REST_BELOW;
        if (! $rest && ! in_array($session->type, WeeklyPatternService::HARD_TYPES, true)) {
            return null;
        }

        $before = collect(SessionRecommendation::FIELDS)->mapWithKeys(fn ($f) => [$f => $session->{$f}])->all();
        $after  = $rest ? $this->restDay($r) : $this->easyRun($session, $r);

        $why    = $r['reasons'] ? ': ' . implode(', ', $r['reasons']) : '';
        $reason = "Deine Readiness liegt heute bei {$r['score']}{$why}. "
            . ($rest ? 'Ein Ruhetag bringt dich weiter als diese Einheit.' : 'Heute lieber locker, die harte Einheit hat mehr Wert, wenn du erholt bist.');

        $recommendation = SessionRecommendation::create([
            'user_id'             => $user->id,
            'training_session_id' => $session->id,
            'source'              => SessionRecommendation::SOURCE_READINESS,
            'reason'              => mb_substr($reason, 0, 300),
            'before'              => $before,
            'after'               => $after,
        ]);

        // Telegram schickt der Observer; ohne Telegram eine Push.
        if (! $user->hasTelegram()) {
            app(WebPushService::class)->sendToUser(
                $user,
                $rest ? 'Heute besser Ruhetag?' : 'Heute lieber locker?',
                "Readiness {$r['score']}. Vorschlag: " . SessionRecommendationObserver::describe($after),
                '/dashboard',
            );
        }

        Log::info('Readiness: Vorschlag angelegt', [
            'user_id'    => $user->id,
            'session_id' => $session->id,
            'score'      => $r['score'],
            'rest'       => $rest,
        ]);

        return $recommendation;
    }

    /** @return array<string, mixed> */
    private function restDay(array $r): array
    {
        return [
            'type'            => 'rest',
            'title'           => 'Ruhetag',
            'description'     => 'Deine Readiness ist niedrig. Heute erholen, gern spazieren gehen.',
            'distance_km'     => null,
            'duration_min'    => null,
            'pace_target'     => null,
            'zone'            => null,
            'intensity'       => 'low',
            'workout_variant' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function easyRun(TrainingSession $session, array $r): array
    {
        $draft = $session->replicate();
        $draft->fill([
            'type'         => 'easy_run',
            'title'        => 'Lockerer Lauf',
            'description'  => 'Deine Readiness ist heute niedrig. Ganz locker laufen, das Tempo darf sich leicht anfühlen.',
            'duration_min' => min((int) ($session->duration_min ?: 40), 40),
            'intensity'    => 'low',
            'zone'         => 2,
        ]);
        $draft->steps = null;
        $this->structure->apply($draft, 'locker');

        return collect(SessionRecommendation::FIELDS)->mapWithKeys(fn ($f) => [$f => $draft->{$f}])->all();
    }
}
