<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Einladungen zur Registrierung — nur Admins erzeugen sie.
 *
 * Ein Link ist an niemanden gebunden; die Notiz („für Christopher") ist
 * nur die Erinnerung des Admins, wem er ihn gegeben hat.
 */
class AdminInvitationController extends Controller
{
    public function index(): Response
    {
        $invitations = Invitation::with(['creator:id,name', 'redeemer:id,name'])
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
            ]);

        return Inertia::render('Admin/Invitations/Index', [
            'invitations' => $invitations,
            'validDays'   => Invitation::VALID_DAYS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'note' => 'nullable|string|max:120',
        ]);

        Invitation::issue($request->user(), $data['note'] ?? null);

        return back()->with('success', 'Einladungslink erstellt.');
    }

    /** Zurückziehen, solange er nicht eingelöst ist. Ein Konto bleibt bestehen. */
    public function revoke(Invitation $invitation): RedirectResponse
    {
        if ($invitation->used_at === null && $invitation->revoked_at === null) {
            $invitation->update(['revoked_at' => now()]);
        }

        return back()->with('success', 'Einladung zurückgezogen.');
    }
}
