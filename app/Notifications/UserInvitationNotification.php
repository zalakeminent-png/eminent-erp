<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when an administrator creates an account. The user chooses their
 * own password through the standard reset-token flow; no password is ever
 * generated, displayed or emailed.
 */
class UserInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('erp.frontend_url'), '/').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
            'invite' => 1,
        ]);
        $expire = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Your '.config('app.name').' account has been created')
            ->greeting("Hello {$notifiable->first_name},")
            ->line('An administrator has created an account for you.')
            ->action('Set Your Password', $url)
            ->line("This link expires in {$expire} minutes. Ask your administrator to resend it if it expires.");
    }
}
