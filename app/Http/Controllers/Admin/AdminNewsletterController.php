<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendNewsletterJob;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AdminNewsletterController extends Controller
{
    public function index()
    {
        $newsletters = Newsletter::with('creator:id,name')
            ->orderByDesc('created_at')
            ->get();

        $subscriberCount = User::where('newsletter_opt_in', true)->count();
        $totalUsers      = User::count();

        return Inertia::render('Admin/Newsletter/Index', [
            'newsletters'     => $newsletters,
            'subscriberCount' => $subscriberCount,
            'totalUsers'      => $totalUsers,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject'      => 'required|string|max:255',
            'preview_text' => 'nullable|string|max:255',
            'html_content' => 'required|string',
        ]);

        $newsletter = Newsletter::create([
            ...$validated,
            'created_by' => Auth::id(),
        ]);

        return back()->with('success', "Newsletter \"{$newsletter->subject}\" gespeichert.");
    }

    public function update(Request $request, Newsletter $newsletter)
    {
        if ($newsletter->isSent()) {
            return back()->withErrors(['error' => 'Bereits versendete Newsletter können nicht bearbeitet werden.']);
        }

        $validated = $request->validate([
            'subject'      => 'required|string|max:255',
            'preview_text' => 'nullable|string|max:255',
            'html_content' => 'required|string',
        ]);

        $newsletter->update($validated);

        return back()->with('success', 'Newsletter aktualisiert.');
    }

    public function send(Newsletter $newsletter)
    {
        if ($newsletter->isSent()) {
            return back()->withErrors(['error' => 'Dieser Newsletter wurde bereits versendet.']);
        }

        $recipientCount = User::where('newsletter_opt_in', true)->count();

        // Mark as sent immediately so the UI reflects it at once
        $newsletter->update([
            'sent_at'    => now(),
            'sent_count' => $recipientCount,
        ]);

        SendNewsletterJob::dispatch($newsletter->id);

        return back()->with('success', "Newsletter versendet an {$recipientCount} Abonnenten.");
    }

    public function destroy(Newsletter $newsletter)
    {
        if ($newsletter->isSent()) {
            return back()->withErrors(['error' => 'Bereits versendete Newsletter können nicht gelöscht werden.']);
        }

        $newsletter->delete();

        return back()->with('success', 'Newsletter gelöscht.');
    }
}
