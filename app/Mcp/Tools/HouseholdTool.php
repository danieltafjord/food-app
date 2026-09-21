<?php

namespace App\Mcp\Tools;

use App\Enums\ApiTokenScope;
use App\Models\Household;
use Laravel\Mcp\Server\Tool;

/**
 * Base for tools that act on the household the calling API token is pinned
 * to (resolved by the AuthenticateApiToken middleware).
 */
abstract class HouseholdTool extends Tool
{
    /**
     * The token scope a caller needs for this tool to be offered to them.
     */
    protected ApiTokenScope $requiredScope = ApiTokenScope::Read;

    public function shouldRegister(): bool
    {
        return (bool) request()->attributes->get('api_token')?->allows($this->requiredScope);
    }

    protected function household(): Household
    {
        return request()->attributes->get('current_household');
    }
}
