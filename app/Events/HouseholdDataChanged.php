<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * The "doorbell" for live sync: a household's data moved to a new sync
 * version. It carries no rows. Devices that hear it pull `sync_version >
 * cursor` through the normal sync endpoint, so authorization, conflict
 * handling and tombstones stay in one place. A device whose cursor is already
 * at `version` ignores it.
 *
 * Sent right away, not queued: a queued broadcast would wait on the queue
 * worker, and speed is the point.
 */
class HouseholdDataChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(public int $householdId, public int $version) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('household.'.$this->householdId)];
    }

    public function broadcastAs(): string
    {
        return 'synced';
    }

    /** @return array{version: int} */
    public function broadcastWith(): array
    {
        return ['version' => $this->version];
    }
}
