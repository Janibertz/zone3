<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\LiveActivity\LiveActivityService;
use App\Services\Widget\WidgetPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * iOS-Widget (11.11.2026). Das Widget läuft als eigener Prozess ohne die
 * Session der App; es holt seine Daten über einen Token, den die App beim
 * Öffnen im geteilten Schlüsselbund ablegt (wie beim Kalender-Abo: wer die
 * Adresse hat, sieht die Einheiten der nächsten zwei Wochen).
 */
class WidgetController extends Controller
{
    /** Gibt der App den Token, legt ihn beim ersten Mal an. */
    public function token(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->widget_token) {
            $user->forceFill(['widget_token' => Str::random(48)])->save();
        }

        return response()->json([
            'token' => $user->widget_token,
            'url'   => route('widget.show', $user->widget_token),
        ]);
    }

    public function show(string $token, WidgetPayload $payload): JsonResponse
    {
        $user = User::where('widget_token', $token)->first();

        abort_unless($user && $user->is_active !== false && ! $user->waitlisted_at, 404);

        return response()->json($payload->build($user), 200, [
            'Cache-Control' => 'private, max-age=300',
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Die App meldet ein Token der Live Activity — ohne Session, weil iOS
     * sie dafür nur kurz im Hintergrund weckt (`LiveActivityRegistrar`).
     * Derselbe Token wie beim Widget ist der Schutz.
     */
    public function liveActivity(Request $request, string $token, LiveActivityService $service): JsonResponse
    {
        $user = User::where('widget_token', $token)->first();

        abort_unless($user && $user->is_active !== false && ! $user->waitlisted_at, 404);

        $data = $request->validate([
            'kind'        => ['required', 'in:start,update'],
            'token'       => ['required', 'string', 'regex:/^[0-9a-f]{32,250}$/'],
            'activity_id' => ['required_if:kind,update', 'nullable', 'string', 'max:100'],
            'day'         => ['required_if:kind,update', 'nullable', 'date_format:Y-m-d'],
        ]);

        $service->register($user, $data);

        return response()->json(['ok' => true]);
    }
}
