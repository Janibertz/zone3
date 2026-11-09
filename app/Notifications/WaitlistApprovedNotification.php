<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WaitlistApprovedNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Du bist dabei – Zone3')
            ->greeting('Hallo ' . $notifiable->name . '!')
            ->line('Dein Zugang zu Zone3 ist freigeschaltet. Ein paar Fragen, dann steht dein erster Plan.')
            ->action('Zone3 öffnen', url('/onboarding'))
            ->salutation('Dein Zone3-Team');
    }
}
