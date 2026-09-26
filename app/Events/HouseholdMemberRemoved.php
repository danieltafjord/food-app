<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Someone was removed from a household, or left it. Sent on the household's
 * live-sync channel (the one HouseholdDataChanged rings), so the removed
 * member's devices, still subscribed, learn they have lost access at once;
 * they cannot subscribe again (see routes/channels.php). The other members'
 * devices can refresh their member list.
 *
 * Sent once the removal has committed, right away rather than queued.
 */
class HouseholdMemberRemoved implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    public function __construct(public int $householdId, public int $userId) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('household.'.$this->householdId)];
    }

    public function broadcastAs(): string
    {
        return 'household.member-removed';
    }

    /** @return array{user_id: int} */
    public function broadcastWith(): array
    {
        return ['user_id' => $this->userId];
    }
}
