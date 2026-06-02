<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminRepliedNotification extends Notification
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
        $url = url(route('support.tickets.show', $this->ticket->id));

        return (new MailMessage)
            ->subject('[Zone3] Antwort auf dein Ticket #' . $this->ticket->id . ': ' . $this->ticket->subject)
            ->greeting('Hallo ' . $notifiable->name . '!')
            ->line('Das Zone3-Team hat auf dein Ticket geantwortet.')
            ->line('**Betreff:** ' . $this->ticket->subject)
            ->line('**Antwort:**')
            ->line($this->reply->message)
            ->action('Ticket ansehen', $url)
            ->salutation('Dein Zone3-Team');
    }
}
