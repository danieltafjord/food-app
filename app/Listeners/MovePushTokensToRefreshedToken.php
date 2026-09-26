<?php

namespace App\Listeners;

use App\Models\PushToken;
use Illuminate\Support\Facades\Context;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Events\AccessTokenRevoked;

class MovePushTokensToRefreshedToken
{
    private const REVOKED = 'passport.revoked_access_token';

    /**
     * Passport only fires this from the refresh-token grant, just before it
     * issues the replacement token in the same request.
     */
    public function handleRevoked(AccessTokenRevoked $event): void
    {
        Context::addHidden(self::REVOKED, $event->tokenId);
    }

    /**
     * Keep the install's push registration on the session it belongs to, so
     * signing that session out (or revoking it) still silences the install.
     */
    public function handleCreated(AccessTokenCreated $event): void
    {
        $previous = Context::pullHidden(self::REVOKED);
        if (! is_string($previous) || $event->userId === null) {
            return;
        }

        PushToken::query()
            ->where('access_token_id', $previous)
            ->where('user_id', $event->userId)
            ->update(['access_token_id' => $event->tokenId]);
    }
}
