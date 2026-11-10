<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use App\Models\User;
use App\Notifications\ContactMessageNotification;
use App\Services\WebPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Impressum mit Kontaktformular (§ 5 Abs. 1 Nr. 2 DDG: neben der E-Mail ein
 * zweiter schneller Weg). Öffentlich, ohne Konto erreichbar.
 */
class ContactController extends Controller
{
    public function __construct(private readonly WebPushService $push) {}

    public function show()
    {
        return Inertia::render('Impressum');
    }

    public function store(Request $request)
    {
        // Honigtopf: Menschen sehen das Feld nicht. Ein Bot füllt es aus und
        // bekommt dieselbe Bestätigung, ohne dass etwas gespeichert wird.
        if (filled($request->input('website'))) {
            return back()->with('success', 'Danke, deine Nachricht ist angekommen.');
        }

        $data = $request->validate([
            'name'    => 'nullable|string|max:120',
            'email'   => 'required|email|max:255',
            'message' => 'required|string|min:10|max:5000',
        ]);

        $contact = ContactMessage::create([
            ...$data,
            'user_id' => $request->user()?->id,
        ]);

        // Die Mail geht an die Kontaktadresse aus dem Impressum, nicht an die
        // Konto-Adressen der Admins — die sind privat und nicht die, die dort steht.
        try {
            Notification::route('mail', config('services.contact.address'))
                ->notify(new ContactMessageNotification($contact));
        } catch (\Throwable $e) {
            Log::warning('Kontaktformular: Mail fehlgeschlagen', ['contact_id' => $contact->id, 'error' => $e->getMessage()]);
        }

        foreach (User::where('is_admin', true)->get() as $admin) {
            try {
                $this->push->sendToUser($admin, 'Kontaktformular', Str::limit($contact->email . ': ' . $contact->message, 120), '/admin/contact');
            } catch (\Throwable $e) {
                Log::warning('Kontaktformular: Push fehlgeschlagen', ['contact_id' => $contact->id, 'error' => $e->getMessage()]);
            }
        }

        return back()->with('success', 'Danke, deine Nachricht ist angekommen. Ich melde mich per Mail.');
    }
}
