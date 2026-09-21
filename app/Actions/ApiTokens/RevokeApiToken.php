<?php

namespace App\Actions\ApiTokens;

use App\Models\ApiTokenDetail;

class RevokeApiToken
{
    public function handle(ApiTokenDetail $apiToken): void
    {
        $apiToken->token?->revoke();
    }
}
