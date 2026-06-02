<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewTicketReplyNotification extends Notification
{
    public function __construct(
        public SupportTicket      $ticket,
        public SupportTicketReply $reply,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $url = url(route('admin.support.show', $this->ticket->id));

        return (new MailMessage)
            ->subject('[Zone3] Neue Antwort auf Ticket #' . $this->ticket->id . ': ' . $this->ticket->subject)
            ->greeting('Neue Antwort von ' . $this->reply->user->name)
            ->line('**Ticket:** #' . $this->ticket->id . ' – ' . $this->ticket->subject)
            ->line('**Antwort:**')
            ->line($this->reply->message)
            ->action('Ticket ansehen', $url)
            ->salutation('Dein Zone3-System');
    }
}
