<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use App\Services\Auth\Access;
use App\Services\Auth\SignupSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Anmelden mit einem Link per Mail (seit 09.11.2026) — auch für neue Konten.
 *
 * - Das Konto entsteht erst beim Einlösen, nicht beim Anfordern: ein
 *   Tippfehler in der Adresse legt nichts an.
 * - Der Link gilt `MINUTES` Minuten und einmal. Das Token liegt nur gehasht
 *   im Cache.
 * - Der Link öffnet eine Seite mit einem Knopf, angemeldet wird erst per
 *   POST. Mail-Scanner (Outlook, Virenprüfer) öffnen Links vorab und hätten
 *   ihn sonst verbraucht.
 * - Die Antwort ist immer dieselbe, ob es die Adresse gibt oder nicht.
 * - In der iOS-App öffnet der Link die App (Universal Link `/login/link/*`).
 */
class MagicLinkController extends Controller
{
    use PasswordlessLogin;

    public const MINUTES = 30;

    public function __construct(private readonly Access $access) {}

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|string|email|max:255']);
        $email = Str::lower($data['email']);

        $key = 'magic-link:' . sha1($email . '|' . $request->ip());
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->withErrors(['email' => 'Zu viele Anfragen. Warte bitte ein paar Minuten.']);
        }
        RateLimiter::hit($key, 600);

        $token = Str::random(48);
        Cache::put(self::cacheKey($token), $email, now()->addMinutes(self::MINUTES));
        // Der Link wird oft in einem anderen Browser geöffnet (Mail-App →
        // Safari); die Quelle reist deshalb im Cache mit.
        if ($source = SignupSource::current()) {
            Cache::put(self::cacheKey($token) . ':source', $source, now()->addMinutes(self::MINUTES));
        }

        try {
            Notification::route('mail', $email)->notify(new MagicLinkNotification(route('login.link.show', $token), self::MINUTES));
        } catch (Throwable $e) {
            Log::error('Anmeldelink: Mail ging nicht raus', ['error' => $e->getMessage()]);

            return back()->withErrors(['email' => 'Die Mail ging gerade nicht raus. Versuch es bitte später noch einmal.']);
        }

        return back()->with('linkSent', $email);
    }

    public function show(string $token): Response
    {
        $email = Cache::get(self::cacheKey($token));

        return Inertia::render('Auth/MagicLink', [
            'token' => $email ? $token : null,
            'email' => $email,
        ]);
    }

    public function consume(Request $request, string $token): RedirectResponse
    {
        $email = Cache::pull(self::cacheKey($token));
        $source = Cache::pull(self::cacheKey($token) . ':source');
        if (! $email) {
            return redirect()->route('login')->withErrors(['email' => 'Der Link ist abgelaufen oder schon benutzt. Fordere bitte einen neuen an.']);
        }

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if ($user) {
            if (! $user->email_verified_at) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }
        } else {
            $user = $this->access->register($email, null, array_filter(['signup_source' => $source ?: SignupSource::current()]));
        }

        return redirect($this->signIn($request, $user));
    }

    private static function cacheKey(string $token): string
    {
        return 'magic-link:token:' . hash('sha256', $token);
    }
}
