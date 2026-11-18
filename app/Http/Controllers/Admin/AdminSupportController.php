<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\User;
use App\Notifications\AdminRepliedNotification;
use App\Services\Admin\AdminInsights;
use App\Services\Admin\AdminPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AdminSupportController extends Controller
{
    /** Seit 18.11.2026 im Postfach. */
    public function index()
    {
        return redirect()->route('admin.inbox.index');
    }

    public function show(SupportTicket $ticket, AdminInsights $insights, AdminPushService $push)
    {
        $ticket->load(['user', 'replies.user']);
        $u = $ticket->user;

        return Inertia::render('Admin/Support/Show', [
            'ticket'  => $ticket,
            'context' => $u instanceof User ? [
                'first'       => $insights->first($u->name),
                'short'       => $insights->short($u->name),
                'connections' => $insights->connections($u),
                'way'         => $insights->way($u) ?? 'unbekannt',
                'reaches'     => match ($push->channel($u)) {
                    'telegram' => 'Telegram',
                    'push'     => 'Push',
                    default    => 'sonst nirgends',
                },
            ] : null,
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket, AdminPushService $push)
    {
        $data = $request->validate([
            'message' => 'required|string|max:5000',
            'resolve' => 'nullable|boolean',
        ]);

        $reply = SupportTicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id'   => Auth::id(),
            'message'   => $data['message'],
            'is_admin'  => true,
        ]);

        $ticket->update([
            'last_reply_at' => now(),
            'status'        => ! empty($data['resolve']) ? 'resolved' : ($ticket->status === 'open' ? 'in_progress' : $ticket->status),
        ]);

        $reply->load('user');

        try {
            $ticket->load('user');
            $ticket->user->notify(new AdminRepliedNotification($ticket, $reply));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Admin reply notification failed: ' . $e->getMessage());
        }

        // Die Mail geht immer; dazu Telegram, wenn verbunden, sonst Push (Jan: Telegram ist Quelle 1).
        $via = $ticket->user
            ? $push->notify($ticket->user, 'Antwort auf deine Nachricht', mb_strimwidth($data['message'], 0, 300, '…'), "/support/tickets/{$ticket->id}")
            : null;

        return back()->with('success', match ($via) {
            'telegram' => 'Antwort gesendet, per Mail und Telegram.',
            'push'     => 'Antwort gesendet, per Mail und Push.',
            default    => 'Antwort per Mail gesendet.',
        });
    }

    public function updateStatus(Request $request, SupportTicket $ticket)
    {
        $request->validate(['status' => 'required|in:open,in_progress,resolved,closed']);
        $ticket->update(['status' => $request->status]);

        return back()->with('success', 'Status aktualisiert.');
    }
}
