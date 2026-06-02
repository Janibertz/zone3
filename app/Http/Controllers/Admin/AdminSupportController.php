<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\User;
use App\Notifications\NewTicketReplyNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AdminSupportController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->get('status', 'open');

        $tickets = SupportTicket::with('user')
            ->withCount('replies')
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->latest('updated_at')
            ->paginate(30)
            ->withQueryString();

        $counts = [
            'open'        => SupportTicket::where('status', 'open')->count(),
            'in_progress' => SupportTicket::where('status', 'in_progress')->count(),
            'resolved'    => SupportTicket::where('status', 'resolved')->count(),
            'closed'      => SupportTicket::where('status', 'closed')->count(),
            'all'         => SupportTicket::count(),
        ];

        return Inertia::render('Admin/Support/Index', compact('tickets', 'counts', 'status'));
    }

    public function show(SupportTicket $ticket)
    {
        $ticket->load(['user', 'replies.user']);

        return Inertia::render('Admin/Support/Show', ['ticket' => $ticket]);
    }

    public function reply(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate(['message' => 'required|string|max:5000']);

        $reply = SupportTicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id'   => Auth::id(),
            'message'   => $data['message'],
            'is_admin'  => true,
        ]);

        $ticket->update(['last_reply_at' => now()]);

        $reply->load('user');

        $ticket->user->notify(new NewTicketReplyNotification($ticket, $reply));

        return back();
    }

    public function updateStatus(Request $request, SupportTicket $ticket)
    {
        $request->validate(['status' => 'required|in:open,in_progress,resolved,closed']);
        $ticket->update(['status' => $request->status]);

        return back()->with('success', 'Status aktualisiert.');
    }
}
