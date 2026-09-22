<?php

namespace App\Actions\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Blocks an account without deleting anything: future logins are refused,
 * and every current session and API token stops working immediately.
 */
class DeactivateUser
{
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->forceFill(['deactivated_at' => now()])->save();

            DB::table('oauth_refresh_tokens')
                ->whereIn('access_token_id', $user->tokens()->select('id'))
                ->update(['revoked' => true]);
            $user->tokens()->update(['revoked' => true]);

            DB::table('sessions')->where('user_id', $user->id)->delete();
        });
    }
}
