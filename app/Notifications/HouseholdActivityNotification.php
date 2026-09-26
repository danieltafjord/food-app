<?php

namespace App\Notifications;

use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A push about what another household member did, already worded in the
 * recipient's language (see SendHouseholdActivityNotifications).
 *
 * `data.url` is the app route a tap opens; `data.scope` is the presence scope
 * the notification is about, so the app can stay quiet when that screen is
 * already open.
 */
class HouseholdActivityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public string $title,
        public string $body,
        public array $data,
        public ?string $threadId = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [ExpoPushChannel::class];
    }

    /**
     * @return array<string, mixed>
     */
    public function toExpoPush(object $notifiable): array
    {
        return array_filter([
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
            'sound' => 'default',
            'channelId' => 'household',
            'threadId' => $this->threadId,
            // Pointless once the moment has passed (a trip, tonight's dinner).
            'ttl' => 6 * 60 * 60,
        ], fn ($value): bool => $value !== null);
    }
}
