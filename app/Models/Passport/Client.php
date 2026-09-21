<?php

namespace App\Models\Passport;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client as BaseClient;
use Laravel\Passport\Scope;

class Client extends BaseClient
{
    /**
     * Determine if the client should skip the authorization prompt.
     *
     * Only clients an administrator has marked as trusted (our own mobile
     * app) bypass the consent screen. Being ownerless is not enough: MCP
     * clients register themselves dynamically and are ownerless too.
     *
     * @param  array<int, Scope>  $scopes
     */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return (bool) $this->trusted;
    }
}
