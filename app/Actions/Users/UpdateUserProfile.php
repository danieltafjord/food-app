<?php

namespace App\Actions\Users;

use App\Data\UserProfileData;
use App\Models\User;

class UpdateUserProfile
{
    /**
     * Set the name shown to the rest of the household. Choosing one also
     * answers the app's prompt for people whose provider shared no name.
     */
    public function handle(User $user, UserProfileData $data): User
    {
        $user->forceFill([
            'name' => trim($data->name),
            'needs_name' => false,
        ])->save();

        return $user;
    }
}
