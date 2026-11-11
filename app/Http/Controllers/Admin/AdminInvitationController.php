<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Auth\Access;
use App\Services\Auth\SignupSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Einladungen zur Registrierung — nur Admins erzeugen sie.
 *
 * Ein Link ist an niemanden gebunden; die Notiz („für Christopher") ist
 * nur die Erinnerung des Admins, wem er ihn gegeben hat.
 *
 * Seit 09.11.2026 steht hier auch die Warteliste: wer über Apple oder den
 * Link per Mail kommt, wartet hier auf die Freischaltung (oder ist sofort
 * drin, wenn der Schalter auf „offen“ steht). Eine Einladung umgeht sie.
 */
class AdminInvitationController extends Controller
{
    public function index(): Response
    {
        $invitations = Invitation::with(['creator:id,name', 'redeemer:id,name', 'redeemers:id,name,invitation_id'])
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (Invitation $i) => [
                'id'         => $i->id,
                'note'       => $i->note,
                'url'        => $i->url(),
                'status'     => $i->status(),
                'created_at' => $i->created_at?->toIso8601String(),
                'expires_at' => $i->expires_at->toIso8601String(),
                'used_at'    => $i->used_at?->toIso8601String(),
                'creator'    => $i->creator?->name,
                'redeemer'   => $i->redeemer ? ['id' => $i->redeemer->id, 'name' => $i->redeemer->name] : null,
                'multi'      => $i->isMulti(),
                'max_uses'   => $i->max_uses,
                'uses_count' => $i->uses_count,
                'source'     => $i->source,
                'redeemers'  => $i->isMulti()
                    ? $i->redeemers->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()
                    : [],
            ]);

        $waiting = User::whereNotNull('waitlisted_at')
            ->orderBy('waitlisted_at')
            ->get(['id', 'name', 'email', 'apple_sub', 'waitlisted_at'])
            ->map(fn (User $u) => [
                'id'    => $u->id,
                'name'  => $u->name,
                'email' => $u->email,
                'via'   => $u->apple_sub ? 'Apple' : 'Link per Mail',
                'since' => $u->waitlisted_at->toIso8601String(),
            ]);

        return Inertia::render('Admin/Invitations/Index', [
            'invitations' => $invitations,
            'validDays'   => Invitation::VALID_DAYS,
            'mode'        => Access::mode(),
            'waiting'     => $waiting,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'note'     => 'nullable|string|max:120',
            'multi'    => 'nullable|boolean',
            'max_uses' => 'nullable|integer|min:2|max:10000',
            'days'     => 'nullable|integer|min:1|max:' . Invitation::MAX_DAYS,
            'source'   => 'nullable|string|max:40',
        ]);

        $multi = (bool) ($data['multi'] ?? false);

        Invitation::issue(
            $request->user(),
            $data['note'] ?? null,
            $multi ? ($data['max_uses'] ?? null) : 1,
            (int) ($data['days'] ?? Invitation::VALID_DAYS),
            SignupSource::clean($data['source'] ?? null),
        );

        return back()->with('success', $multi ? 'Link für mehrere erstellt.' : 'Einladungslink erstellt.');
    }

    /** Warteliste oder offen — gilt für Apple und den Link per Mail. */
    public function mode(Request $request): RedirectResponse
    {
        $data = $request->validate(['mode' => 'required|in:' . Access::WAITLIST . ',' . Access::OPEN]);
        Access::setMode($data['mode']);

        return back()->with('success', $data['mode'] === Access::OPEN
            ? 'Neue Konten sind ab jetzt sofort drin.'
            : 'Neue Konten landen ab jetzt auf der Warteliste.');
    }

    public function approve(User $user, Access $access): RedirectResponse
    {
        $access->approve($user);

        return back()->with('success', "{$user->name} ist freigeschaltet.");
    }

    /** Ablehnen löscht das Konto — es hat noch nichts außer Name und Mail. */
    public function reject(User $user): RedirectResponse
    {
        abort_unless($user->waitlisted_at !== null && ! $user->is_admin, 404);
        $name = $user->name;
        $user->delete();

        return back()->with('success', "{$name} wurde abgelehnt und gelöscht.");
    }

    /**
     * Zurückziehen, solange er noch Plätze hat. Ein Link für mehrere lässt
     * sich auch nach dem ersten Konto zurückziehen; Konten bleiben bestehen.
     */
    public function revoke(Invitation $invitation): RedirectResponse
    {
        if (! $invitation->isFull() && $invitation->revoked_at === null) {
            $invitation->update(['revoked_at' => now()]);
        }

        return back()->with('success', 'Einladung zurückgezogen.');
    }
}
