<?php

namespace App\Notifications;

use App\Models\HouseholdInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HouseholdInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public HouseholdInvitation $invitation) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $household = $this->invitation->household;
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $url = "{$base}/invitations/{$this->invitation->token}";

        return (new MailMessage)
            ->subject("You've been invited to join {$household->name}")
            ->line("You've been invited to join the \"{$household->name}\" household on Food App.")
            ->action('Accept invitation', $url)
            ->line('This invitation expires on '.$this->invitation->expires_at->toFormattedDateString().'.');
    }
}
