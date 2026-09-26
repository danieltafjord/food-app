<?php

namespace App\Notifications;

use App\Models\HouseholdInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent in the inviter's language (the caller sets `locale()`). The subject is
 * fixed text: household and inviter names are chosen by whoever sends the
 * invitation, so they appear only in the body.
 */
class HouseholdInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public HouseholdInvitation $invitation)
    {
        // Queue only once the invitation row is committed, so a revoke can't race the mail.
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->invitation->loadMissing(['household', 'invitedBy']);
        $household = $this->invitation->household;
        $inviter = $this->invitation->invitedBy;
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $url = "{$base}/invitations/{$this->invitation->token}";
        $locale = app()->getLocale();

        return (new MailMessage)
            ->subject(__('invitations.mail_subject'))
            ->greeting(__('invitations.mail_greeting'))
            ->line($inviter
                ? __('invitations.mail_intro', ['inviter' => $inviter->name, 'household' => $household->name])
                : __('invitations.mail_intro_anonymous', ['household' => $household->name]))
            ->action(__('invitations.mail_action'), $url)
            ->line(__('invitations.mail_expires', [
                'date' => $this->invitation->expires_at->locale($locale === 'no' ? 'nb' : $locale)->isoFormat('LL'),
            ]))
            ->line(__('invitations.mail_ignore'))
            ->salutation(__('invitations.mail_salutation'));
    }
}
