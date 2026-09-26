<?php

namespace App\Jobs;

use App\Models\PushToken;
use App\Notifications\Channels\ExpoPushChannel;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Read Expo's receipts for pushes ExpoPushChannel sent a while ago: forget
 * installs APNs or FCM no longer know (the app was removed), and report the
 * failures that mean pushes are not getting through at all, such as
 * InvalidCredentials. A receipt Expo does not have yet is skipped.
 */
class CheckExpoPushReceipts implements ShouldQueue
{
    use Queueable;

    /** Expo returns at most this many receipts per request. */
    private const BATCH_SIZE = 1000;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /**
     * @param  array<string, string>  $tickets  ticket id → push token it was sent to
     */
    public function __construct(public array $tickets, public CarbonInterface $sentAt) {}

    public function handle(): void
    {
        $unregistered = [];
        foreach (array_chunk($this->tickets, self::BATCH_SIZE, preserve_keys: true) as $batch) {
            $receipts = (array) ExpoPushChannel::request()
                ->post(config('services.expo.receipts_url'), ['ids' => array_map('strval', array_keys($batch))])
                ->throw()
                ->json('data', []);

            foreach ($batch as $ticketId => $token) {
                $receipt = $receipts[$ticketId] ?? null;
                if (($receipt['status'] ?? null) !== 'error') {
                    continue;
                }
                if (($receipt['details']['error'] ?? null) === 'DeviceNotRegistered') {
                    $unregistered[] = $token;
                } else {
                    ExpoPushChannel::logError('receipt', $receipt['details']['error'] ?? null, $receipt['message'] ?? null);
                }
            }
        }

        if ($unregistered !== []) {
            // An install that registered again since was reinstalled or re-enabled.
            PushToken::query()
                ->whereIn('token', $unregistered)
                ->where('updated_at', '<=', $this->sentAt)
                ->delete();
        }
    }
}
