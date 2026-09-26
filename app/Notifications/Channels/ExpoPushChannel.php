<?php

namespace App\Notifications\Channels;

use App\Jobs\CheckExpoPushReceipts;
use App\Models\PushToken;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a notification to every app install of a user through Expo's push
 * service, which relays it to APNs and FCM.
 *
 * A notification provides `toExpoPush($notifiable)`: an Expo message without
 * `to`. Expo answers each message with a ticket; most delivery failures
 * (an uninstalled app, bad APNs or FCM credentials) only show up in the
 * receipt APNs/FCM give Expo later, which CheckExpoPushReceipts reads
 * RECEIPT_DELAY_MINUTES after sending. An install Expo reports as
 * unregistered, in its ticket or its receipt, loses its token.
 */
class ExpoPushChannel
{
    /** Expo accepts at most this many messages per request. */
    private const BATCH_SIZE = 100;

    /** Expo has usually heard back from APNs and FCM by then; it keeps receipts for a day. */
    public const RECEIPT_DELAY_MINUTES = 15;

    /** Errors that mean pushes are failing for everyone (credentials, payload, rate). */
    private const SERIOUS_ERRORS = ['InvalidCredentials', 'MessageTooBig', 'MessageRateExceeded'];

    public function send(User $notifiable, Notification $notification): void
    {
        $tokens = $notifiable->pushTokens()->deliverable()->pluck('token')->all();
        if ($tokens === [] || ! method_exists($notification, 'toExpoPush')) {
            return;
        }

        /** @var array<string, mixed> $message */
        $message = $notification->toExpoPush($notifiable);

        $sentAt = now();
        $receipts = [];
        $unregistered = [];
        foreach (array_chunk($tokens, self::BATCH_SIZE) as $batch) {
            $response = self::request()
                ->post(config('services.expo.push_url'), array_map(fn (string $token): array => ['to' => $token] + $message, $batch))
                ->throw();
            foreach ((array) $response->json('errors', []) as $error) {
                self::logError('request', is_array($error) ? ($error['code'] ?? null) : null, is_array($error) ? ($error['message'] ?? null) : null);
            }

            // One ticket per message, in order. A failed message never fails the others.
            $tickets = (array) $response->json('data', []);
            foreach ($batch as $index => $token) {
                $ticket = $tickets[$index] ?? null;
                if (($ticket['status'] ?? null) === 'ok' && is_string($ticket['id'] ?? null)) {
                    $receipts[$ticket['id']] = $token;
                } elseif (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') {
                    $unregistered[] = $token;
                } elseif (($ticket['status'] ?? null) === 'error') {
                    self::logError('ticket', $ticket['details']['error'] ?? null, $ticket['message'] ?? null);
                }
            }
        }

        if ($unregistered !== []) {
            PushToken::query()->whereIn('token', $unregistered)->delete();
        }
        if ($receipts !== []) {
            CheckExpoPushReceipts::dispatch($receipts, $sentAt)
                ->delay($sentAt->addMinutes(self::RECEIPT_DELAY_MINUTES));
        }
    }

    /**
     * A request to Expo's push API. Rate limiting (429), server errors and
     * dropped connections are retried with backoff; anything else is final.
     */
    public static function request(): PendingRequest
    {
        $request = Http::acceptJson()->timeout(15)->retry([500, 2000, 5000], when: fn (Throwable $exception): bool => $exception instanceof ConnectionException
            || ($exception instanceof RequestException && ($exception->response->status() === 429 || $exception->response->serverError())));
        if (filled(config('services.expo.access_token'))) {
            $request = $request->withToken(config('services.expo.access_token'));
        }

        return $request;
    }

    /**
     * Report a push Expo, APNs or FCM refused. Credential, size and rate
     * problems affect every push, so they are errors; the rest are warnings.
     */
    public static function logError(string $stage, mixed $error, mixed $message): void
    {
        $context = ['stage' => $stage, 'error' => is_string($error) ? $error : null, 'message' => is_string($message) ? $message : null];

        in_array($error, self::SERIOUS_ERRORS, true)
            ? Log::error('Expo push failed: '.$error.'.', $context)
            : Log::warning('Expo push failed.', $context);
    }
}
