<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends BaseResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        return (new MailMessage)
            ->subject('Passwort zurücksetzen – Zone3')
            ->greeting('Hallo!')
            ->line('Du hast eine Anfrage zum Zurücksetzen deines Passworts erhalten.')
            ->action('Passwort zurücksetzen', $url)
            ->line('Dieser Link ist 60 Minuten gültig.')
            ->line('Falls du diese Anfrage nicht gestellt hast, ist kein Handeln erforderlich.')
            ->salutation('Dein Zone3-Team');
    }
}
