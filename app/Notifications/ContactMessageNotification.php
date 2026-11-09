<?php

namespace App\Notifications;

use App\Models\ContactMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail an die Admins. „Antworten" geht direkt an den Absender (Reply-To),
 * die Nachricht steht zusätzlich unter /admin/contact — falls die Mail nie ankommt.
 */
class ContactMessageNotification extends Notification
{
    public function __construct(public ContactMessage $contact) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $from = $this->contact->name ? $this->contact->name . ' (' . $this->contact->email . ')' : $this->contact->email;

        return (new MailMessage)
            ->subject('[Zone3] Kontaktformular: ' . $from)
            ->replyTo($this->contact->email, $this->contact->name)
            ->greeting('Neue Nachricht über das Impressum')
            ->line('**Von:** ' . $from)
            ->line('**Nachricht:**')
            ->line($this->contact->message)
            ->action('Im Admin ansehen', url(route('admin.contact.index')))
            ->salutation('Dein Zone3-System');
    }
}
