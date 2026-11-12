<?php

namespace App\Services\Auth;

use App\Models\AppSetting;
use App\Models\User;
use App\Notifications\WaitlistApprovedNotification;
use App\Services\WebPushService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Wer neu hereinkommt — über „Mit Apple fortfahren“ oder den Link per Mail
 * (seit 09.11.2026, Entscheidung von Jan).
 *
 * Der Admin stellt unter /admin/invitations ein, ob ein neues Konto sofort
 * drin ist (`open`) oder auf der Warteliste landet (`waitlist`, Vorgabe).
 * Beide Male bekommen die Admins einen Push. Ein Einladungslink bleibt der
 * Weg vorbei an der Warteliste.
 */
class Access
{
    public const SETTING = 'registration_mode';
    public const WAITLIST = 'waitlist';
    public const OPEN = 'open';

    public function __construct(private readonly WebPushService $push) {}

    public static function mode(): string
    {
        return AppSetting::get(self::SETTING) === self::OPEN ? self::OPEN : self::WAITLIST;
    }

    public static function setMode(string $mode): void
    {
        AppSetting::set(self::SETTING, $mode === self::OPEN ? self::OPEN : self::WAITLIST);
    }

    /**
     * Legt ein Konto ohne Passwort an. Wer später doch eins will, nimmt
     * „Passwort vergessen“.
     */
    public function register(string $email, ?string $name, array $extra = []): User
    {
        $waiting = self::mode() === self::WAITLIST;

        $user = User::create($extra + [
            'name'              => $this->name($name, $email),
            'email'             => Str::lower($email),
            'password'          => Hash::make(Str::random(48)),
            'waitlisted_at'     => $waiting ? now() : null,
            'signup_source'     => SignupSource::current(),
        ]);
        // Die Adresse ist bestätigt: Apple hat sie geprüft bzw. der Link kam an.
        // Kein eigenes Passwort: Konto löschen fragt dann keins ab (App Store 5.1.1(v)).
        $user->forceFill(['email_verified_at' => now(), 'has_password' => false])->save();

        $this->tellAdmins($user, $waiting);

        return $user;
    }

    public function approve(User $user): void
    {
        if ($user->waitlisted_at === null) {
            return;
        }

        $user->update(['waitlisted_at' => null]);

        try {
            $user->notify(new WaitlistApprovedNotification);
        } catch (Throwable $e) {
            Log::warning('Freischaltung: Mail ging nicht raus', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
        $this->safePush($user, 'Du bist dabei', 'Dein Zugang zu Zone3 ist freigeschaltet. Los geht’s!', '/onboarding');
    }

    private function tellAdmins(User $user, bool $waiting): void
    {
        $title = $waiting ? 'Neue Anmeldung wartet' : 'Neue Anmeldung';
        $body  = $waiting
            ? "{$user->name} ({$user->email}) wartet auf Freischaltung."
            : "{$user->name} ({$user->email}) ist jetzt dabei.";
        if ($user->signup_source) {
            $body .= " Über: {$user->signup_source}.";
        }

        foreach (User::where('is_admin', true)->get() as $admin) {
            $this->safePush($admin, $title, $body, '/admin/invitations');
        }
    }

    private function safePush(User $user, string $title, string $body, string $url): void
    {
        try {
            $this->push->sendToUser($user, $title, $body, $url);
        } catch (Throwable $e) {
            Log::warning('Push fehlgeschlagen', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    private function name(?string $name, string $email): string
    {
        $name = trim((string) $name);
        if ($name !== '') {
            return mb_substr($name, 0, 255);
        }

        // Vor dem @, „jan.anders“ → „Jan Anders“. Im Profil änderbar.
        $local = Str::before($email, '@');
        $guess = Str::title(trim(preg_replace('/[._\-+0-9]+/', ' ', $local)));

        return $guess !== '' ? $guess : 'Läufer';
    }
}
