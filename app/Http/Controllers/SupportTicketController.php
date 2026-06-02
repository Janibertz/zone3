<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\User;
use App\Notifications\NewSupportTicketNotification;
use App\Notifications\NewTicketReplyNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class SupportTicketController extends Controller
{
    public function index()
    {
        $tickets = SupportTicket::where('user_id', Auth::id())
            ->withCount('replies')
            ->latest()
            ->get();

        return Inertia::render('Support/Index', ['tickets' => $tickets]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'subject'     => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'type'        => 'required|in:bug,improvement,question,other',
        ]);

        $ticket = SupportTicket::create([
            ...$data,
            'user_id' => Auth::id(),
        ]);

        $ticket->load('user');

        try {
            User::where('is_admin', true)->each(function (User $admin) use ($ticket) {
                $admin->notify(new NewSupportTicketNotification($ticket));
            });
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Ticket notification failed: ' . $e->getMessage());
        }

        return back()->with('success', 'Ticket #' . $ticket->id . ' wurde erstellt. Wir melden uns so schnell wie möglich!');
    }

    public function show(SupportTicket $ticket)
    {
        abort_if($ticket->user_id !== Auth::id(), 403);

        $ticket->load(['replies.user']);

        return Inertia::render('Support/Show', ['ticket' => $ticket]);
    }

    public function reply(Request $request, SupportTicket $ticket)
    {
        abort_if($ticket->user_id !== Auth::id(), 403);
        abort_if(in_array($ticket->status, ['resolved', 'closed']), 422, 'Ticket ist geschlossen.');

        $data = $request->validate(['message' => 'required|string|max:5000']);

        $reply = SupportTicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id'   => Auth::id(),
            'message'   => $data['message'],
            'is_admin'  => false,
        ]);

        $ticket->update(['last_reply_at' => now(), 'status' => 'open']);

        $reply->load('user');

        try {
            User::where('is_admin', true)->each(function (User $admin) use ($ticket, $reply) {
                $admin->notify(new NewTicketReplyNotification($ticket, $reply));
            });
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Ticket reply notification failed: ' . $e->getMessage());
        }

        return back();
    }
}
