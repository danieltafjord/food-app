<?php

namespace App\Actions\ApiTokens;

use App\Models\ApiTokenDetail;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ListApiTokens
{
    /**
     * The user's active, self-created API tokens, newest first. Tokens held
     * by connected OAuth apps are managed through their grant instead.
     *
     * @return Collection<int, ApiTokenDetail>
     */
    public function handle(User $user): Collection
    {
        return ApiTokenDetail::query()
            ->with(['token', 'household:id,name'])
            ->whereNull('can_write')
            ->whereHas('token', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('revoked', false)
                ->where('expires_at', '>', now()))
            ->latest('id')
            ->get();
    }
}
