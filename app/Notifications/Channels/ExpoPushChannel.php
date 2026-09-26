<?php

namespace App\Notifications\Channels;

use App\Models\PushToken;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

/**
 * Sends a notification to every app install of a user through Expo's push
 * service, which relays it to APNs and FCM.
 *
 * A notification provides `toExpoPush($notifiable)`: an Expo message without
 * `to`. An install Expo reports as unregistered (the app was removed, or
 * notifications were turned off) loses its token.
 */
class ExpoPushChannel
{
    /** Expo accepts at most this many messages per request. */
    private const BATCH_SIZE = 100;

    public function send(User $notifiable, Notification $notification): void
    {
        $tokens = $notifiable->pushTokens()->pluck('token')->all();
        if ($tokens === [] || ! method_exists($notification, 'toExpoPush')) {
            return;
        }

        /** @var array<string, mixed> $message */
        $message = $notification->toExpoPush($notifiable);

        foreach (array_chunk($tokens, self::BATCH_SIZE) as $batch) {
            $request = Http::acceptJson()->timeout(15);
            if (filled(config('services.expo.access_token'))) {
                $request = $request->withToken(config('services.expo.access_token'));
            }

            $tickets = $request
                ->post(config('services.expo.push_url'), array_map(fn (string $token): array => ['to' => $token] + $message, $batch))
                ->throw()
                ->json('data', []);

            $unregistered = [];
            foreach ($batch as $index => $token) {
                if (($tickets[$index]['details']['error'] ?? null) === 'DeviceNotRegistered') {
                    $unregistered[] = $token;
                }
            }
            if ($unregistered !== []) {
                PushToken::query()->whereIn('token', $unregistered)->delete();
            }
        }
    }
}
