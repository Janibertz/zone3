<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Inertia\Inertia;

class AdminContactMessageController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/ContactMessages/Index', [
            'messages' => ContactMessage::latest()->limit(200)->get(['id', 'name', 'email', 'message', 'handled_at', 'created_at']),
        ]);
    }

    public function handle(ContactMessage $contact)
    {
        $contact->update(['handled_at' => $contact->handled_at ? null : now()]);

        return back();
    }

    /** Erledigte Anfragen löscht der Admin — so steht es in der Datenschutzerklärung. */
    public function destroy(ContactMessage $contact)
    {
        $contact->delete();

        return back()->with('success', 'Nachricht gelöscht.');
    }
}
