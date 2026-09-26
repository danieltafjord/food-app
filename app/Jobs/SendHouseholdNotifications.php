<?php

namespace App\Jobs;

use App\Actions\Notifications\SendHouseholdActivityNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Bundle one household's pending activity into notifications.
 *
 * Dispatched by RecordHouseholdActivity for every batch it stores: once right
 * away when the batch holds something that goes out at once (a first tick,
 * someone joining), and once after the quiet period, when the batch's edits
 * can be bundled. Nothing runs while nobody edits shared data, so the app can
 * sleep. Runs for the same household never overlap, so nothing is sent twice.
 */
class SendHouseholdNotifications implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $householdId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->householdId))->releaseAfter(10)->expireAfter(120)];
    }

    public function handle(SendHouseholdActivityNotifications $send): void
    {
        $send->handle($this->householdId);
    }
}
