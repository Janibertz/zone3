<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewSupportTicketNotification extends Notification
{
    public function __construct(public SupportTicket $ticket) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $url = url(route('admin.support.show', $this->ticket->id));

        return (new MailMessage)
            ->subject('[Zone3] Neues Support-Ticket #' . $this->ticket->id . ': ' . $this->ticket->subject)
            ->greeting('Neues Ticket eingegangen!')
            ->line('**Von:** ' . $this->ticket->user->name . ' (' . $this->ticket->user->email . ')')
            ->line('**Typ:** ' . $this->ticket->typeLabel())
            ->line('**Betreff:** ' . $this->ticket->subject)
            ->line('**Nachricht:**')
            ->line($this->ticket->description)
            ->action('Ticket ansehen', $url)
            ->salutation('Dein Zone3-System');
    }
}
