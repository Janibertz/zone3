<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MagicLinkNotification extends Notification
{
    public function __construct(private readonly string $url, private readonly int $minutes) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Dein Anmeldelink – Zone3')
            ->greeting('Hallo!')
            ->line('Tipp auf den Knopf, um dich bei Zone3 anzumelden. Ein Passwort brauchst du nicht.')
            ->action('Bei Zone3 anmelden', $this->url)
            ->line("Der Link gilt {$this->minutes} Minuten und nur einmal.")
            ->line('Falls du keinen Link angefordert hast, kannst du diese Mail einfach löschen.')
            ->salutation('Dein Zone3-Team');
    }
}
