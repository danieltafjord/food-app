<?php

namespace App\Listeners;

use App\Models\ApiTokenDetail;
use App\Models\OAuthHouseholdGrant;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;

class PinOAuthTokenToHousehold
{
    /**
     * Tokens issued to untrusted (third-party / MCP) clients become API
     * tokens pinned to the household the user approved. Without an approval
     * on record the token is revoked, so such a client can never end up
     * holding an unrestricted mobile-API token.
     */
    public function handle(AccessTokenCreated $event): void
    {
        $client = Passport::client()->newQuery()->find($event->clientId);

        if (! $client || $client->trusted || $client->hasGrantType('personal_access') || $event->userId === null) {
            return;
        }

        $grant = OAuthHouseholdGrant::query()
            ->where('user_id', $event->userId)
            ->where('client_id', $event->clientId)
            ->first();

        if (! $grant) {
            Passport::token()->newQuery()->whereKey($event->tokenId)->update(['revoked' => true]);

            return;
        }

        ApiTokenDetail::query()->create([
            'token_id' => $event->tokenId,
            'household_id' => $grant->household_id,
            'can_write' => $grant->can_write,
        ]);
    }
}
