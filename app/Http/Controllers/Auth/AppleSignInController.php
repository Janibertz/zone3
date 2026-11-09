<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\Access;
use App\Services\Auth\AppleIdToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * „Mit Apple fortfahren“ (seit 09.11.2026).
 *
 * Zwei Wege, ein Ergebnis:
 * - **iOS-App:** das native Sheet (`ios/App/App/AppleSignInPlugin.swift`)
 *   liefert das id_token, die Seite schickt es an `native()`. Die Nonce
 *   liegt in der Session — der Aufruf kommt von derselben Seite.
 * - **Browser:** Weiterleitung zu Apple, Rückweg als Formular-POST von
 *   appleid.apple.com. Der kommt ohne Session-Cookie an (SameSite=Lax),
 *   darum liegen state und nonce im Cache wie bei Garmin.
 *
 * Gefunden wird ein Konto über Apples `sub`, sonst über die bestätigte
 * Mailadresse; sonst entsteht eins ({@see Access::register()}).
 */
class AppleSignInController extends Controller
{
    use PasswordlessLogin;

    private const AUTHORIZE_URL = 'https://appleid.apple.com/auth/authorize';

    public function __construct(private readonly AppleIdToken $tokens, private readonly Access $access) {}

    public static function webEnabled(): bool
    {
        return filled(config('services.apple_signin.client_id'));
    }

    /** Für die App: eine Nonce, die das native Sheet an Apple weitergibt. */
    public function nonce(Request $request): JsonResponse
    {
        $nonce = Str::random(40);
        $request->session()->put('apple_signin_nonce', $nonce);

        return response()->json(['nonce' => $nonce]);
    }

    public function native(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identity_token' => 'required|string|max:8000',
            'given_name'     => 'nullable|string|max:100',
            'family_name'    => 'nullable|string|max:100',
        ]);

        $nonce = (string) $request->session()->pull('apple_signin_nonce', '');

        try {
            $claims = $this->tokens->verify($data['identity_token'], [config('services.apple_signin.bundle_id')], $nonce);
            $user = $this->resolve($claims, trim(($data['given_name'] ?? '') . ' ' . ($data['family_name'] ?? '')));
        } catch (RuntimeException $e) {
            Log::warning('Apple-Anmeldung (App) abgelehnt', ['error' => $e->getMessage()]);

            return response()->json(['message' => $this->message($e)], 422);
        }

        return response()->json(['redirect' => $this->signIn($request, $user)]);
    }

    public function redirect(): RedirectResponse
    {
        abort_unless(self::webEnabled(), 404);

        $state = Str::random(40);
        $nonce = Str::random(40);
        Cache::put("apple-signin:state:$state", $nonce, now()->addMinutes(10));

        return redirect()->away(self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id'     => config('services.apple_signin.client_id'),
            'redirect_uri'  => route('auth.apple.callback'),
            'response_type' => 'code id_token',
            'response_mode' => 'form_post',
            'scope'         => 'name email',
            'state'         => $state,
            'nonce'         => $nonce,
        ], '', '&', PHP_QUERY_RFC3986));
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(self::webEnabled(), 404);

        if ($request->filled('error')) {
            // „user_cancelled_authorize“: einfach zurück, ohne Fehlermeldung.
            return redirect()->route('login');
        }

        $nonce = Cache::pull('apple-signin:state:' . (string) $request->input('state'));
        if (! $nonce) {
            return redirect()->route('login')->withErrors(['apple' => 'Die Anmeldung mit Apple ist abgelaufen. Versuch es bitte noch einmal.']);
        }

        // Den Namen schickt Apple nur beim allerersten Mal, als JSON-Feld.
        $person = json_decode((string) $request->input('user', ''), true);
        $name = trim(($person['name']['firstName'] ?? '') . ' ' . ($person['name']['lastName'] ?? ''));

        try {
            $claims = $this->tokens->verify((string) $request->input('id_token'), [config('services.apple_signin.client_id')], $nonce);
            $user = $this->resolve($claims, $name);
        } catch (RuntimeException $e) {
            Log::warning('Apple-Anmeldung (Web) abgelehnt', ['error' => $e->getMessage()]);

            return redirect()->route('login')->withErrors(['apple' => $this->message($e)]);
        }

        return redirect($this->signIn($request, $user));
    }

    /** @param array{sub: string, email: ?string, email_verified: bool, is_private_email: bool} $claims */
    private function resolve(array $claims, string $name): User
    {
        $user = User::where('apple_sub', $claims['sub'])->first();
        if ($user) {
            return $user;
        }

        $email = $claims['email'];
        if (! $email) {
            throw new RuntimeException('Apple hat keine Mailadresse geschickt.');
        }

        // Eine von Apple bestätigte Adresse gehört diesem Menschen — dann
        // ist es sein bestehendes Konto, und Apple wird daran gehängt.
        $existing = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existing) {
            if (! $claims['email_verified']) {
                throw new RuntimeException('Apple hat die Mailadresse nicht bestätigt.');
            }
            $existing->update(['apple_sub' => $claims['sub']]);

            return $existing;
        }

        return $this->access->register($email, $name, ['apple_sub' => $claims['sub']]);
    }

    private function message(RuntimeException $e): string
    {
        return $e->getMessage() === 'Apple hat keine Mailadresse geschickt.'
            ? 'Apple hat uns keine Mailadresse geschickt. Melde dich bitte mit dem Link per E-Mail an.'
            : 'Die Anmeldung mit Apple hat nicht geklappt. Versuch es bitte noch einmal.';
    }
}
