<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Inertia\Inertia;

class AdminContactMessageController extends Controller
{
    /** Seit 18.11.2026 im Postfach. */
    public function index()
    {
        return redirect()->route('admin.inbox.index');
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
