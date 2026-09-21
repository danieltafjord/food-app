<?php

namespace App\Actions\ApiTokens;

use App\Models\OAuthHouseholdGrant;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

class RevokeOAuthGrant
{
    /**
     * Disconnect a third-party app: forget the approval and revoke every
     * access and refresh token it holds for the user, so it must ask again.
     */
    public function handle(OAuthHouseholdGrant $grant): void
    {
        DB::transaction(function () use ($grant): void {
            $tokens = Passport::token()->newQuery()
                ->where('user_id', $grant->user_id)
                ->where('client_id', $grant->client_id);

            Passport::refreshToken()->newQuery()
                ->whereIn('access_token_id', (clone $tokens)->select('id'))
                ->update(['revoked' => true]);

            $tokens->update(['revoked' => true]);

            $grant->delete();
        });
    }
}
